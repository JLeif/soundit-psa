<?php

namespace App\Services\ControlD;

use App\Models\Client;
use App\Models\ControlDOnboardingIntent;
use App\Models\User;
use App\Support\ControlDConfig;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Dark B3: explicit stage + execute, no published verb, job, retry or reconciliation.
 * The unique active_client_id is a durable non-expiring per-client lock. It survives
 * process death and remains held on uncertainty. No cache lease can expire mid-POST.
 * Rejected/bound release it; staged/posted/uncertain never self-expire or self-retry.
 */
class ControlDOnboardingStaged
{
    public function __construct(private readonly ControlDClient $vendor, private readonly ControlDProvisioning $provisioning) {}

    public function stageOrganization(#[\SensitiveParameter] User $actor, int $clientId, #[\SensitiveParameter] string $name, #[\SensitiveParameter] string $contactEmail, int $requireMfa, string $statsEndpoint): string
    {
        if (trim($name) === '' || trim($name) !== $name || mb_strlen($name) > 255
            || preg_match('/[\x00-\x1f\x7f]/', $name) || ! filter_var($contactEmail, FILTER_VALIDATE_EMAIL)
            || ! in_array($requireMfa, [0, 1], true) || ! preg_match('/\A[A-Za-z0-9_-]{1,255}\z/', $statsEndpoint)) {
            throw new ControlDClientException('Explicit organization name, contact email, MFA choice and analytics region are required.');
        }

        return $this->stage($actor, $clientId, 'organization', [
            'name' => $name, 'contact_email' => $contactEmail, 'twofa_req' => $requireMfa, 'stats_endpoint' => $statsEndpoint,
        ]);
    }

    public function stageCode(#[\SensitiveParameter] User $actor, int $clientId, string $icon, #[\SensitiveParameter] ?string $pin = null, #[\SensitiveParameter] ?string $namePrefix = null): string
    {
        return $this->stage($actor, $clientId, 'code', ['icon' => $icon, 'pin' => $pin, 'name_prefix' => $namePrefix]);
    }

    private function independent(): void
    {
        if ((new Client)->getConnection()->transactionLevel() > 0) {
            throw new ControlDClientException('Onboarding requires an independent transaction.');
        }
    }

    private function authorize(#[\SensitiveParameter] ?User $actor): void
    {
        if ($actor === null || ! $actor->exists || ! $actor->is_active || ! $actor->isAdmin()) {
            throw new ControlDClientException('Onboarding requires an active Admin user.');
        }
    }

    private function eligible(?Client $client, string $operation): void
    {
        if ($client === null || ($operation === 'organization' && $client->controld_org_id !== null)
            || ($operation === 'code' && (! is_string($client->controld_org_id) || trim($client->controld_org_id) === ''
                || $client->getRawOriginal('controld_provisioning_code') !== null || $client->getRawOriginal('controld_deactivation_pin') !== null))) {
            throw new ControlDClientException('Client is missing, deleted or already has conflicting Control D state.');
        }
    }

    private function available(): void
    {
        if (! ControlDConfig::isEnabled() || ! $this->vendor->isConfigured()) {
            throw new ControlDClientException('Control D is disabled or unconfigured.');
        }
    }

    private function stage(#[\SensitiveParameter] User $actor, int $clientId, string $operation, #[\SensitiveParameter] array $payload): string
    {
        $this->independent();
        $this->authorize($actor);
        $this->authorize(User::find($actor->getKey()));
        $this->available();
        $this->eligible(Client::find($clientId), $operation);
        $intent = new ControlDOnboardingIntent;
        $intent->forceFill([
            'id' => (string) Str::uuid(), 'client_id' => $clientId, 'actor_id' => $actor->getKey(),
            'active_client_id' => $clientId, 'operation' => $operation, 'state' => 'staged',
            'phase' => 'preflight', 'payload' => $payload,
        ]);
        try {
            // Autocommit unique INSERT is the lock acquisition. A loser is refused,
            // never queued for execution after the winner, even if the winner crashes.
            $intent->saveOrFail();
        } catch (UniqueConstraintViolationException) {
            throw new ControlDClientException('A Control D onboarding intent already owns this client; do not retry.');
        } catch (\Throwable) {
            throw new ControlDClientException('Control D intent could not be durably staged; no vendor call was made.');
        }

        return $intent->id;
    }

    /** Returns only the durable id. Read the internal evidence row for the outcome. */
    public function execute(#[\SensitiveParameter] User $actor, string $intentId): string
    {
        $this->independent();
        $this->authorize($actor);
        $this->authorize(User::find($actor->getKey()));
        $this->available();
        $intent = ControlDOnboardingIntent::find($intentId);
        if ($intent === null || $intent->actor_id != $actor->getKey()) {
            throw new ControlDClientException('Control D intent does not belong to this actor.');
        }
        // Cheap definite refusal before any local work or read-only vendor GET; the
        // authoritative atomic one-shot admission is admit(), immediately before the write.
        if ($intent->state !== 'staged') {
            throw new ControlDClientException('Control D intent is not executable; do not retry.');
        }
        try {
            $this->eligible(Client::find($intent->client_id), $intent->operation);
            if ($intent->operation === 'organization') {
                $this->organization($intent, $actor);
            } else {
                $this->code($intent, $actor);
            }
        } catch (ControlDOrganizationUncertainException $e) {
            $this->finish($intent, 'uncertain', $e->phase, $e->orgPk, $e->orgPk);
        } catch (ControlDWriteUncertainException $e) {
            $this->finish($intent, 'uncertain', $e->phase, $e->orgPk, $e->provisionPk);
        } catch (ControlDWriteRejectedException $e) {
            $this->finish($intent, 'rejected', 'post', null, null, $e->reasonCode,
                $e->isReadOnlyKey() ? 'vendor key is read-only' : 'vendor rejected the write');
        } catch (\Throwable $e) {
            // Refused before admission: no POST can have been issued, so this stays a
            // definite local refusal. The intent remains staged and executable once the
            // cause is fixed, instead of an uncertain row holding the lock indefinitely.
            if ($intent->state !== 'posted') {
                if ($e instanceof ControlDClientException) {
                    throw $e;
                }
                throw new ControlDClientException('Control D intent was refused before admission; no vendor write was made.');
            }
            // Never leak SQL bindings, request PII, secrets or raw vendor text. A
            // durable posted row/lock is retained if even this update fails.
            $this->finish($intent, 'uncertain', $intent->phase, $intent->org_pk, $intent->vendor_pk);
        }

        return $intentId;
    }

    /**
     * Atomic one-shot admission, taken at the last moment before the intended vendor
     * write. posted means MAY have been sent, not success; a crash immediately after
     * this commit requires manual reconciliation too. Everything before it is local
     * validation or a read-only GET, so a refusal there provably precedes any POST.
     */
    private function admit(ControlDOnboardingIntent $intent): void
    {
        $admitted = ControlDOnboardingIntent::whereKey($intent->id)->where('state', 'staged')
            ->where('active_client_id', $intent->client_id)->update(['state' => 'posted', 'phase' => 'post', 'updated_at' => now()]);
        if ($admitted !== 1) {
            throw new ControlDClientException('Control D intent is not executable; do not retry.');
        }
        // In-memory state matches the durable row before anything is sent, so a later
        // failure is classified as post-admission even if this read-back itself fails.
        $intent->forceFill(['state' => 'posted', 'phase' => 'post']);
        $intent->refresh();
    }

    private function finish(ControlDOnboardingIntent $intent, string $state, string $phase, ?string $orgPk, ?string $pk, ?int $reasonCode = null, ?string $reason = null): void
    {
        try {
            $intent->forceFill(['state' => $state, 'phase' => $phase, 'org_pk' => $orgPk, 'vendor_pk' => $pk,
                'reason_code' => $reasonCode, 'reason' => $reason ?? 'outcome requires reconciliation; do not retry',
                'active_client_id' => $state === 'rejected' ? null : $intent->client_id])->saveOrFail();
        } catch (\Throwable) {
            throw new ControlDClientException('Control D intent outcome could not be recorded; do not retry.');
        }
    }

    private function organization(ControlDOnboardingIntent $intent, #[\SensitiveParameter] User $actor): void
    {
        $payload = $intent->payload;
        $this->admit($intent);
        $response = $this->vendor->requestParent('POST', 'organizations/suborg', $payload);
        $row = $response['body']->organization ?? null;
        if (! $row instanceof \stdClass || ! is_string($row->PK ?? null)
            || ! preg_match('/\A[A-Za-z0-9_-]{1,255}\z/', $row->PK)) {
            throw new ControlDOrganizationUncertainException(null, 'post');
        }
        $pk = $row->PK;
        // Commit the orphan handle before any further I/O; failed persistence never
        // licenses another POST. Stored intent remains posted if this write fails.
        $intent->forceFill(['org_pk' => $pk, 'vendor_pk' => $pk, 'phase' => 'readback'])->saveOrFail();
        $response = $this->vendor->requestParent('GET', 'organizations/sub_organizations');
        $rows = $response['body']->sub_organizations ?? null;
        if (! is_array($rows)) {
            throw new ControlDOrganizationUncertainException($pk, 'readback');
        }
        $matches = 0;
        foreach ($rows as $listed) {
            if (! $listed instanceof \stdClass || ! is_string($listed->PK ?? null) || ! is_string($listed->name ?? null)) {
                throw new ControlDOrganizationUncertainException($pk, 'readback');
            }
            if ($listed->PK === $pk) {
                if ($listed->name !== $payload['name']) {
                    throw new ControlDOrganizationUncertainException($pk, 'readback');
                }
                $matches++;
            }
        }
        if ($matches !== 1 || strlen($pk) > 50) {
            throw new ControlDOrganizationUncertainException($pk, 'readback');
        }
        $intent->phase = 'local-persistence';
        $this->bind($intent, $actor, $pk, null);
    }

    private function code(ControlDOnboardingIntent $intent, #[\SensitiveParameter] User $actor): void
    {
        $client = Client::find($intent->client_id);
        $payload = $intent->payload;
        $fields = $this->codeFields($client, $payload);
        $intent->org_pk = $client->controld_org_id;
        $intent->saveOrFail();
        $intentId = $intent->id;
        $created = $this->provisioning->create($intent->org_pk, $fields, $payload['pin'], static function (string $pk) use ($intentId): void {
            if (ControlDOnboardingIntent::whereKey($intentId)->where('state', 'posted')->update([
                'vendor_pk' => $pk, 'phase' => 'read-back', 'updated_at' => now(),
            ]) !== 1) {
                throw new ControlDClientException('Control D intent checkpoint failed.');
            }
        }, fn () => $this->admit($intent));
        $intent->forceFill(['vendor_pk' => $created['PK'], 'phase' => 'local-persistence'])->saveOrFail();
        $this->bind($intent, $actor, $intent->org_pk, $created);
    }

    private function bind(ControlDOnboardingIntent $intent, #[\SensitiveParameter] User $actor, string $orgPk, #[\SensitiveParameter] ?array $created): void
    {
        // Secret-bearing parameters are annotated, not captured in a transaction
        // callback. One attempt only; nothing vendor-facing is inside this section.
        $connection = (new Client)->getConnection();
        $connection->beginTransaction();
        try {
            $locked = ControlDOnboardingIntent::whereKey($intent->id)->lockForUpdate()->firstOrFail();
            if ($locked->state !== 'posted' || $locked->active_client_id != $intent->client_id) {
                throw new ControlDClientException('Control D intent ownership changed.');
            }
            $client = Client::query()->lockForUpdate()->find($intent->client_id);
            $this->authorize(User::query()->lockForUpdate()->find($actor->getKey()));
            $this->eligible($client, $intent->operation);
            if ($created === null) {
                if (Client::withTrashed()->where('controld_org_id', $orgPk)->exists()) {
                    throw new ControlDClientException('Control D organization is already mapped.');
                }
                $client->controld_org_id = $orgPk;
            } else {
                if ($client->controld_org_id !== $orgPk) {
                    throw new ControlDClientException('Control D organization mapping changed.');
                }
                $client->controld_provisioning_code = $created['code'];
                $client->controld_deactivation_pin = $created['deactivation_pin'];
            }
            if (! $client->save()) {
                throw new ControlDClientException('Control D persistence was refused.');
            }
            $locked->forceFill(['state' => 'bound', 'phase' => 'local-persistence', 'org_pk' => $orgPk,
                'vendor_pk' => $created['PK'] ?? $orgPk, 'active_client_id' => null,
                'reason' => null, 'reason_code' => null])->saveOrFail();
            $connection->commit();
        } catch (\Throwable) {
            $connection->rollBack();
            if ($created === null) {
                throw new ControlDOrganizationUncertainException($orgPk, 'local-persistence');
            }
            throw new ControlDWriteUncertainException($orgPk, $created['PK'], 'local-persistence');
        }
    }

    private function codeFields(Client $client, #[\SensitiveParameter] array $payload): array
    {
        $profile = ControlDConfig::defaultProfileId();
        $days = ControlDConfig::codeExpiryDays();
        $headroom = ControlDConfig::codeDeviceLimitHeadroom();
        $stats = ControlDConfig::codeAnalyticsLevel();
        $intercept = ControlDConfig::codeInterceptMode();
        foreach ([
            ControlDConfig::TACTICAL_CLIENT_ORG_FIELD_SETTING => ControlDConfig::tacticalClientOrgFieldId(),
            ControlDConfig::DEFAULT_PROFILE_SETTING => $profile,
            ControlDConfig::CODE_EXPIRY_DAYS_SETTING => $days,
            ControlDConfig::CODE_DEVICE_LIMIT_HEADROOM_SETTING => $headroom,
            ControlDConfig::CODE_ANALYTICS_LEVEL_SETTING => $stats,
            ControlDConfig::CODE_INTERCEPT_MODE_SETTING => $intercept,
        ] as $setting => $value) {
            if ($value === null) {
                throw new ControlDClientException($setting.' is required to onboard.');
            }
        }
        if (! preg_match('/\A[012]\z/', $stats)) {
            throw new ControlDClientException('controld_code_analytics_level must be exactly 0, 1 or 2.');
        }
        $count = $client->assets()->count();
        if ($headroom < 0 || $count > 10000 || $headroom > 10000 - $count || $count + $headroom < 1) {
            throw new ControlDClientException('Asset count plus controld_code_device_limit_headroom must be 1..10000.');
        }
        $now = now()->getTimestamp();
        $ceiling = min(PHP_INT_MAX, 253402300799);
        if ($now < 0 || $now >= $ceiling || $days > intdiv($ceiling - $now, 86400)) {
            throw new ControlDClientException('controld_code_expiry_days exceeds the supported timestamp range.');
        }
        $fields = ['icon' => $payload['icon'], 'profile_id' => $profile, 'max' => $count + $headroom,
            'ts_exp' => $now + $days * 86400, 'stats' => (int) $stats, 'intercept_mode' => $intercept];
        if ($payload['name_prefix'] !== null) {
            $fields['name_prefix'] = $payload['name_prefix'];
        }

        return $fields;
    }
}
