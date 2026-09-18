<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The immediate lane (ruled design point 3): a token holding `<tool>:immediate` that
 * passes `execute_at` is admitted straight into scheduled_authorizations with no cockpit
 * proposal and NO HUMAN APPROVER. `approver_user_id` is that absence, and NULL is the
 * only honest way to record it.
 *
 * Deliberately NULL and not a sentinel user id (0, the system user, the AI actor): the
 * sealed envelope and the fire-time lineage check both branch on whether a human
 * approved this row, and a sentinel would read as a human approval to every one of them
 * — including ScheduledPolicy::lineage(), whose human-approved branch accepts EITHER
 * `:staged` or `:immediate`. Token rows must demand `:immediate` specifically, so the
 * two cases have to be distinguishable at the column.
 *
 * `originating_mcp_token_id` was already nullable in the creating migration
 * (2026_09_15_210000): a human-approved native row has no token. The two columns are
 * therefore complementary, and ScheduledApprover is the one type that reads them.
 *
 * Column type restated in full on purpose: a ->change() re-emits the whole definition,
 * so an omitted attribute is silently dropped. unsignedBigInteger matches users.id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_authorizations', function (Blueprint $table) {
            $table->unsignedBigInteger('approver_user_id')->nullable()->change();
        });
    }

    /**
     * Reversing this cannot be lossless while a token-approved row exists: there is no
     * human approver to restore, and inventing one would write a false authorisation
     * record into retained approval evidence. The creating migration's down() already
     * refuses to drop these tables over retained evidence; this refuses for the same
     * reason, one column narrower.
     */
    public function down(): void
    {
        $tokenApproved = \Illuminate\Support\Facades\DB::table('scheduled_authorizations')
            ->whereNull('approver_user_id')->count();

        if ($tokenApproved > 0) {
            throw new RuntimeException(
                "Cannot roll back: {$tokenApproved} scheduled_authorizations row(s) are token-approved "
                .'(NULL approver_user_id). Naming a human approver for them would forge an approval record; '
                .'cancel or let those authorizations settle first.'
            );
        }

        Schema::table('scheduled_authorizations', function (Blueprint $table) {
            $table->unsignedBigInteger('approver_user_id')->nullable(false)->change();
        });
    }
};
