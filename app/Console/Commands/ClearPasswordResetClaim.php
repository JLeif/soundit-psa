<?php

namespace App\Console\Commands;

use App\Models\McpAuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClearPasswordResetClaim extends Command
{
    protected $signature = 'cipp:clear-reset-claim {id} {--operator=} {--reason=} {--checked-cipp-log}';

    protected $description = 'Clear a retained reset claim only after checking CIPP logs for that user';

    public function handle(): int
    {
        $this->warn('Check CIPP logs for this user first. Clearing an active request permits another password mint.');
        $id = (string) $this->argument('id');
        $operator = trim((string) $this->option('operator'));
        $reason = trim((string) $this->option('reason'));
        if (! Str::isUuid($id) || $operator === '' || mb_strlen($operator) > 255
            || $reason === '' || mb_strlen($reason) > 500 || ! $this->option('checked-cipp-log')) {
            $this->error('Require a claim UUID, --operator (1–255 characters), --reason (1–500 characters), and --checked-cipp-log. Do not include passwords.');

            return self::FAILURE;
        }

        $cleared = DB::transaction(function () use ($id, $operator, $reason): bool {
            $claim = DB::table('password_reset_claims')->where('id', $id)->lockForUpdate()->first();
            if ($claim === null) {
                return false;
            }
            McpAuditLog::create([
                'client_id' => $claim->client_id,
                'server_name' => 'artisan',
                'method' => 'cipp:clear-reset-claim',
                'tool_name' => 'cipp_reset_user_password',
                'status' => 'success',
                'duration_ms' => 0,
                'actor_label' => $operator,
                'arguments' => ['claim_id' => $id, 'person_id' => $claim->person_id, 'holder' => $claim->holder, 'started_at' => $claim->started_at, 'reason' => $reason],
                'result_summary' => 'Operator cleared reset claim after checking CIPP logs.',
            ]);
            DB::table('password_reset_claims')->where('id', $id)->delete();

            return true;
        });
        if (! $cleared) {
            $this->error('Claim not found; nothing cleared.');

            return self::FAILURE;
        }
        $this->info('Claim cleared and audit recorded.');

        return self::SUCCESS;
    }
}
