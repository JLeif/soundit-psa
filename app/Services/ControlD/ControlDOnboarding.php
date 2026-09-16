<?php

namespace App\Services\ControlD;

use App\Models\Client;
use App\Support\ControlDConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * B1 only: no caller, route, sub-org creation, Tactical fan-out or automatic retry.
 * A post-write uncertainty requires manual reconciliation, never an automatic re-cut.
 */
class ControlDOnboarding
{
    public function __construct(private readonly ControlDProvisioning $provisioning) {}

    /**
     * No secret-bearing return value. Icon is explicit, never inferred from a mixed fleet.
     * The PIN is a sensitive parameter on every frame of this path that takes it or the
     * request body built from it, so PHP redacts it from those frames' trace arguments even
     * where zend.exception_ignore_args is Off. Nothing here logs it.
     */
    public function create(int $clientId, string $icon, #[\SensitiveParameter] ?string $pin = null, ?string $namePrefix = null): void
    {
        $orgPk = null;
        $created = null;
        try {
            // Hold the client row through the write and local commit: concurrent B1
            // invocations re-read the code under the same lock and cannot clobber it.
            // Exactly one attempt: transaction deadlock retry could repeat a vendor POST.
            DB::transaction(function () use ($clientId, $icon, $pin, $namePrefix, &$orgPk, &$created): void {
                $client = Client::query()->lockForUpdate()->find($clientId);
                if ($client === null) {
                    throw new ControlDClientException('Client is missing or deleted.');
                }
                $orgPk = $client->controld_org_id;
                if (! is_string($orgPk) || trim($orgPk) === '') {
                    throw new ControlDClientException('controld_org_id must be mapped before onboarding.');
                }
                // Raw encrypted value: even undecryptable/empty legacy storage is not
                // permission to overwrite an existing code or PIN.
                if ($client->getRawOriginal('controld_provisioning_code') !== null
                    || $client->getRawOriginal('controld_deactivation_pin') !== null) {
                    throw new ControlDClientException('Client already has stored Control D onboarding secrets; re-cut is not supported.');
                }
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
                // Wire conversion only; A remains the vendor allowlist authority.
                if (! preg_match('/\A[012]\z/', $stats)) {
                    throw new ControlDClientException('controld_code_analytics_level must be exactly 0, 1 or 2.');
                }
                $count = $client->assets()->count();
                // Dashboard clamp adopted as a local safety ceiling, not an API maximum.
                // Negative headroom is refused on its own: bounding only the sum would cut a
                // code whose max is below the client's known asset count, and the stored-secret
                // guard then makes that wrong limit permanent. This writer owns the bounds.
                if ($headroom < 0 || $count > 10000 || $headroom > 10000 - $count || $count + $headroom < 1) {
                    throw new ControlDClientException('Asset count plus controld_code_device_limit_headroom must be 1..10000.');
                }
                $now = now()->getTimestamp();
                // Bound before multiplication/addition. Year 9999 is the application
                // date-serialization ceiling, not a claimed vendor expiry limit.
                $ceiling = min(PHP_INT_MAX, 253402300799);
                if ($now < 0 || $now >= $ceiling || $days > intdiv($ceiling - $now, 86400)) {
                    throw new ControlDClientException('controld_code_expiry_days exceeds the supported timestamp range.');
                }
                $fields = [
                    'icon' => $icon, 'profile_id' => $profile,
                    'max' => $count + $headroom, 'ts_exp' => $now + $days * 86400,
                    'stats' => (int) $stats, 'intercept_mode' => $intercept,
                ];
                if ($namePrefix !== null) {
                    $fields['name_prefix'] = $namePrefix;
                }
                $created = $this->provisioning->create($orgPk, $fields, $pin);
                $client->controld_provisioning_code = $created['code'];
                $client->controld_deactivation_pin = $created['deactivation_pin'];
                if (! $client->save()) {
                    throw new ControlDClientException('Local onboarding persistence was refused.');
                }
            }, 1);
        } catch (\Throwable $e) {
            if ($created !== null) {
                // Includes commit errors. Never chain a DB exception: bindings may
                // contain secrets. Upstream creation cannot be rolled back by SQL.
                throw new ControlDWriteUncertainException($orgPk, $created['PK'], 'local-persistence');
            }
            throw $e;
        }
        // Deliberately no code, PIN, vendor response, org, prefix or client name.
        Log::info('[ControlDOnboarding] Code stored', ['client_id' => $clientId]);
    }
}
