<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Scrub tactical_assets.checks_passing (psa-0pb9m R2).
 *
 * Every value this column has ever held came from Tactical's per-agent checks
 * summary aggregate (calculate_agent_checks, amidaware/tacticalrmm
 * api/tacticalrmm/agents/utils.py:146-184 @ 632a37a4), which counts a check
 * with NO result row at all as passing — so a never-reporting check reads as
 * a pass. That is a manufactured claim, not passing evidence, and it let a
 * never-run 1-of-1 Mac render VERIFIED on every snapshot surface. The sync no
 * longer writes the aggregate here and readers no longer trust the column;
 * this nulls the poisoned residue so nothing can render it in the window
 * before the next sync. The column stays: a future explicit per-check sync
 * (with its own provenance stamp) may repopulate it with real evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tactical_assets')->update(['checks_passing' => null]);
    }

    public function down(): void
    {
        // The scrubbed values were vendor-aggregate claims with no evidentiary
        // value — there is nothing truthful to restore.
    }
};
