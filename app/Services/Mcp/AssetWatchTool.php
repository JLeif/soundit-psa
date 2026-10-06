<?php

namespace App\Services\Mcp;

use App\Enums\TechnicianTier;
use App\Models\Asset;
use App\Models\AssetWatch;
use App\Models\TechnicianActionLog;
use App\Services\Assets\AssetWatchEvaluator;
use App\Support\TechnicianConfig;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Agent-owned asset watch alerts over staff MCP (card K3VEcxtw):
 * create_asset_watch, list_asset_watches, remove_asset_watch.
 *
 * OWNER. Every watch belongs to the calling token's bare McpToken label (the
 * key poll_signals drains by). It is taken from the authenticated token, never
 * from input. A legacy unlabelled token has no owner and is refused.
 *
 * SCOPE. Explicit grant only (McpStaffController::toolAllowed()). The writes
 * change only the caller's own watch rows: no client record, no vendor call.
 * create is fenced to an asset of client_id; list and remove see only the
 * caller's own watches, and another owner's watch_id reads as not found.
 */
class AssetWatchTool
{
    public const CREATE = 'create_asset_watch';

    public const LIST = 'list_asset_watches';

    public const REMOVE = 'remove_asset_watch';

    private const NOT_FOUND = 'Asset watch not found.';

    private const LIST_LIMIT = 200;

    /** @return array<int, string> */
    public static function names(): array
    {
        return [self::CREATE, self::LIST, self::REMOVE];
    }

    public static function handles(string $name): bool
    {
        return in_array($name, self::names(), true);
    }

    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            [
                'name' => self::CREATE,
                'description' => 'Create your own alert on one Tactical RMM asset: tell me when it comes online, or goes offline. '
                    .'Only an asset whose online state Tactical alone maintains is accepted (linked to a Tactical agent, not also linked to NinjaOne or Level); others are refused. '
                    .'Fires are delivered ONLY to your own token\'s signal inbox: read them with poll_signals (event asset.watch_fired; the row\'s watch block carries watch_id, state, last_seen, age_seconds and observed_by). '
                    .'state=online fires when Tactical reports the agent online AND its last_seen is at most 120 seconds old at that observation; an online report with an older last_seen does not fire and the watch stays armed. If the device is already online when you create the watch, the next such observation fires it. '
                    .'state=offline fires when the online flag the device sync writes goes from true to false. Tactical status online maps to true, and both offline and overdue map to false, so an online-to-overdue change fires too. An offline watch starts from the flag the sync last stored: if it reads online at creation, the next false fires; if it reads offline or unknown, the device must be observed online before a drop can fire. '
                    .'Observations come from the Tactical device sync and from a per-minute read of watched agents only. One-shot by default (repeat=false: fires once, then is done); repeat=true re-arms after the opposite state is observed. '
                    .'expires_at is optional ISO-8601 with a timezone (default 7 days, maximum 30 days; a past or unparseable value is refused). An expired watch never fires. '
                    .'DEDUP: one active watch per asset and state per owner. A duplicate returns the existing watch_id with existing=true and changes nothing on it. Requires an explicit token grant.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => ['type' => 'integer', 'description' => 'The client that owns the asset.'],
                        'asset_id' => ['type' => 'integer', 'description' => 'The PSA asset id.'],
                        'state' => ['type' => 'string', 'enum' => AssetWatch::STATES, 'description' => 'online or offline.'],
                        'reason' => ['type' => 'string', 'description' => 'Why you are watching (max 500 characters).'],
                        'expires_at' => ['type' => 'string', 'description' => 'Optional ISO-8601 end time with timezone, e.g. 2026-01-02T15:04:05Z. Default 7 days, maximum 30 days.'],
                        'repeat' => ['type' => 'boolean', 'description' => 'Optional. false (default) fires once; true re-arms after the opposite state.'],
                    ],
                    'required' => ['client_id', 'asset_id', 'state', 'reason'],
                ],
            ],
            [
                'name' => self::LIST,
                'description' => 'List YOUR OWN asset watches (never another token\'s), newest first, at most '.self::LIST_LIMIT.'. Each row has watch_id, client_id, asset_id, state, reason, repeat, status (active, fired, expired or removed), expires_at, fired_at, fire_count, last_observed_state and removed_reason. client_id is an optional filter. Requires an explicit token grant.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => ['type' => 'integer', 'description' => 'Optional: only watches on this client\'s assets.'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => self::REMOVE,
                'description' => 'Remove one of YOUR OWN asset watches so it never fires again. A watch_id that is not yours reads as not found. Do not pass client_id. Requires an explicit token grant.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'watch_id' => ['type' => 'integer', 'description' => 'The watch to remove.'],
                        'reason' => ['type' => 'string', 'description' => 'Why (max 500 characters).'],
                    ],
                    'required' => ['watch_id', 'reason'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments  client_id already lifted out by the boundary
     * @return array<string, mixed>
     */
    public function execute(string $name, array $arguments, ?int $clientId, bool $hasClientId, ?string $owner, string $actorLabel): array
    {
        $owner = trim((string) $owner);
        if ($owner === '') {
            return ['error' => "{$name} requires a labelled staff token; the watch owner is the calling token's label."];
        }

        return match ($name) {
            self::CREATE => $this->create($arguments, $clientId, $owner, $actorLabel),
            self::LIST => $this->list($clientId, $hasClientId, $owner),
            self::REMOVE => $hasClientId
                ? ['error' => 'client_id must be omitted for remove_asset_watch; the watch_id identifies the watch.']
                : $this->remove($arguments, $owner, $actorLabel),
            default => ['error' => "Unknown asset watch tool: {$name}"],
        };
    }

    /** @return array<string, mixed> */
    private function create(array $arguments, ?int $clientId, string $owner, string $actorLabel): array
    {
        if ($clientId === null) {
            return ['error' => 'client_id is required for create_asset_watch and must be a positive integer.'];
        }

        $assetId = self::positiveInt($arguments['asset_id'] ?? null);
        if ($assetId === null) {
            return ['error' => 'asset_id is required and must be a positive integer.'];
        }

        $state = $arguments['state'] ?? null;
        if (! is_string($state) || ! in_array($state, AssetWatch::STATES, true)) {
            return ['error' => 'state must be "online" or "offline".'];
        }

        $reason = is_string($arguments['reason'] ?? null) ? trim($arguments['reason']) : '';
        if ($reason === '' || mb_strlen($reason) > 500) {
            return ['error' => 'reason is required (1 to 500 characters).'];
        }

        $repeat = $arguments['repeat'] ?? false;
        if (! is_bool($repeat)) {
            return ['error' => 'repeat must be a boolean when supplied.'];
        }

        [$expiresAt, $expiryError] = $this->expiresAt($arguments);
        if ($expiryError !== null) {
            return ['error' => $expiryError];
        }

        // Client fence: the same text for "no such asset" and "another client's
        // asset", so the refusal discloses nothing about other clients.
        $asset = Asset::query()->whereKey($assetId)->where('client_id', $clientId)->first();
        if ($asset === null) {
            return ['error' => 'Asset not found for this client.'];
        }

        if (! AssetWatchEvaluator::isTacticalMaintained($asset)) {
            return ['error' => 'Asset watches cover Tactical RMM assets only: this asset is not linked to a Tactical agent, or NinjaOne or Level also maintains its online state.'];
        }

        $key = AssetWatch::activeKeyFor($owner, $asset->id, $state);

        // A lapsed watch the expiry pass has not reached yet must not hold the slot.
        AssetWatch::query()->where('active_key', $key)->where('expires_at', '<=', now())
            ->update(['active_key' => null, 'expired_at' => now()]);

        $existing = AssetWatch::query()->where('active_key', $key)->first();
        if ($existing !== null) {
            return $this->existing($existing);
        }

        try {
            $watch = AssetWatch::create([
                'owner' => $owner,
                'client_id' => $clientId,
                'asset_id' => $asset->id,
                'state' => $state,
                'reason' => $reason,
                'repeat' => $repeat,
                'expires_at' => $expiresAt,
                // An offline watch starts from the flag the sync last stored (see
                // the tool description); an online watch starts unknown.
                'last_observed_state' => $state === AssetWatch::STATE_OFFLINE ? $asset->rmm_online : null,
                'active_key' => $key,
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = AssetWatch::query()->where('active_key', $key)->first();

            return $existing !== null ? $this->existing($existing) : ['error' => 'The watch could not be created; retry.'];
        }

        $this->audit('create_asset_watch', $clientId, $actorLabel, $watch, "created {$state} watch on asset {$asset->id}");

        return [
            'watch_id' => $watch->id,
            'existing' => false,
            'status' => $watch->status(),
            'expires_at' => $watch->expires_at->copy()->utc()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function existing(AssetWatch $watch): array
    {
        return [
            'watch_id' => $watch->id,
            'existing' => true,
            'status' => $watch->status(),
            'expires_at' => $watch->expires_at->copy()->utc()->toIso8601String(),
            'message' => 'An active watch for this asset and state already exists; it was returned unchanged.',
        ];
    }

    /** @return array{0: Carbon|null, 1: string|null} */
    private function expiresAt(array $arguments): array
    {
        $now = Carbon::now();
        $max = $now->copy()->addDays(max(1, (int) config('asset_watch.max_days', 30)));

        if (! array_key_exists('expires_at', $arguments) || $arguments['expires_at'] === null) {
            return [$now->copy()->addDays(max(1, (int) config('asset_watch.default_days', 7))), null];
        }

        $raw = $arguments['expires_at'];
        $parsed = null;
        if (is_string($raw)) {
            foreach (['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP', 'Y-m-d\TH:i:s\Z', 'Y-m-d\TH:i:s.u\Z'] as $format) {
                $candidate = \DateTimeImmutable::createFromFormat('!'.$format, trim($raw), new \DateTimeZone('UTC'));
                $errors = \DateTimeImmutable::getLastErrors();
                if ($candidate !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                    $parsed = Carbon::instance($candidate);
                    break;
                }
            }
        }

        if ($parsed === null) {
            return [null, 'expires_at must be an ISO-8601 date-time with a timezone, e.g. 2026-01-02T15:04:05Z.'];
        }
        if (! $parsed->gt($now)) {
            return [null, 'expires_at is in the past; a watch must end in the future.'];
        }
        if ($parsed->gt($max)) {
            return [null, 'expires_at is more than '.(int) config('asset_watch.max_days', 30).' days away; that is the maximum watch lifetime.'];
        }

        return [$parsed, null];
    }

    /** @return array<string, mixed> */
    private function list(?int $clientId, bool $hasClientId, string $owner): array
    {
        if ($hasClientId && $clientId === null) {
            return ['error' => 'client_id must be a positive integer when supplied.'];
        }

        $query = AssetWatch::query()->where('owner', $owner)->orderByDesc('id')->limit(self::LIST_LIMIT);
        if ($clientId !== null) {
            $query->where('client_id', $clientId);
        }

        $watches = $query->get()->map(fn (AssetWatch $w): array => [
            'watch_id' => $w->id,
            'client_id' => $w->client_id,
            'asset_id' => $w->asset_id,
            'state' => $w->state,
            'reason' => $w->reason,
            'repeat' => $w->repeat,
            'status' => $w->status(),
            'expires_at' => $w->expires_at?->copy()->utc()->toIso8601String(),
            'fired_at' => $w->fired_at?->copy()->utc()->toIso8601String(),
            'fire_count' => $w->fire_count,
            'last_observed_state' => $w->last_observed_state,
            'removed_reason' => $w->removed_reason,
        ])->all();

        return ['count' => count($watches), 'watches' => $watches];
    }

    /** @return array<string, mixed> */
    private function remove(array $arguments, string $owner, string $actorLabel): array
    {
        $watchId = self::positiveInt($arguments['watch_id'] ?? null);
        if ($watchId === null) {
            return ['error' => 'watch_id is required and must be a positive integer.'];
        }

        $reason = is_string($arguments['reason'] ?? null) ? trim($arguments['reason']) : '';
        if ($reason === '' || mb_strlen($reason) > 500) {
            return ['error' => 'reason is required (1 to 500 characters).'];
        }

        // Owner scope: another owner's watch is indistinguishable from none.
        $watch = AssetWatch::query()->whereKey($watchId)->where('owner', $owner)->first();
        if ($watch === null) {
            return ['error' => self::NOT_FOUND];
        }

        if ($watch->removed_at !== null) {
            return ['watch_id' => $watch->id, 'status' => 'removed', 'message' => 'This watch was already removed.'];
        }

        AssetWatch::query()->whereKey($watch->id)->where('owner', $owner)->whereNull('removed_at')
            ->update(['active_key' => null, 'removed_at' => now(), 'removed_reason' => $reason]);

        $this->audit('remove_asset_watch', $watch->client_id, $actorLabel, $watch, "removed watch on asset {$watch->asset_id}");

        return ['watch_id' => $watch->id, 'status' => 'removed'];
    }

    /** Append-only audit row; never throws (a lost audit line must not undo the write). */
    private function audit(string $actionType, ?int $clientId, string $actorLabel, AssetWatch $watch, string $summary): void
    {
        try {
            TechnicianActionLog::create([
                'actor_id' => TechnicianConfig::aiActorUserId(),
                'approver_user_id' => null,
                'actor_label' => $actorLabel,
                'action_type' => $actionType,
                'tier' => TechnicianTier::Auto->value,
                'result_status' => 'executed',
                'ticket_id' => null,
                'client_id' => $clientId,
                'run_id' => null,
                'content_hash' => hash('sha256', $actionType.'|'.$watch->id),
                'summary' => mb_substr('[asset_watch#'.$watch->id.'] '.$summary, 0, 1000),
                'correlation_id' => (string) Str::uuid(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[AssetWatch] Audit row could not be written', ['watch_id' => $watch->id, 'error' => class_basename($e)]);
        }
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,18}$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
