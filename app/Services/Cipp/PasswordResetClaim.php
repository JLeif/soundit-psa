<?php

namespace App\Services\Cipp;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable request-window exclusion. No lease, expiry, or automatic recovery. */
class PasswordResetClaim
{
    /** @return array{id: string, refusal: ?string} */
    public function acquire(int $clientId, int $personId, string $holder): array
    {
        $id = (string) Str::uuid();
        try {
            // Atomic UNIQUE(client_id, person_id) INSERT, never read-then-write.
            DB::table('password_reset_claims')->insert([
                'id' => $id,
                'client_id' => $clientId,
                'person_id' => $personId,
                'holder' => mb_substr($holder, 0, 255),
                'started_at' => CarbonImmutable::now('UTC'),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            $held = DB::table('password_reset_claims')->where('client_id', $clientId)->where('person_id', $personId)->first();
            if ($held === null) {
                // A concurrent clear/release is not permission to silently retry a reset.
                return ['id' => '', 'refusal' => 'Password reset claim changed while acquiring; no reset was sent. Recheck before retrying.'];
            }
            $started = CarbonImmutable::parse($held->started_at, 'UTC')->toAppTz()->format('Y-m-d H:i:s T');

            return ['id' => '', 'refusal' => "Password reset held by {$held->holder}, started {$started}. No reset was sent. Check CIPP logs for this user first; an operator can clear claim {$held->id} with: php artisan cipp:clear-reset-claim {$held->id} --operator=<name> --reason=<reason> --checked-cipp-log"];
        }

        return ['id' => $id, 'refusal' => null];
    }

    public function release(string $id): void
    {
        // ID-fenced: a late response cannot delete a replacement claim after a human clear.
        DB::table('password_reset_claims')->where('id', $id)->delete();
    }

    /** @param array<string, mixed> $upstream */
    public function releaseOnAnswer(string $id, array $upstream): void
    {
        // CIPP-API c04bde0f: Invoke-ExecResetPass.ps1 wraps Set-CIPPResetPassword.ps1
        // in Results; both cloud and synced branches emit state=success. This says
        // CIPP answered, NOT that asynchronous Microsoft writeback completed.
        $status = $upstream['status'] ?? null;
        $results = $upstream['body']['Results'] ?? null;
        if (is_int($status) && $status >= 200 && $status < 300
            && is_array($results) && ! array_is_list($results)
            && ($results['state'] ?? null) === 'success') {
            $this->release($id);
        }
    }
}
