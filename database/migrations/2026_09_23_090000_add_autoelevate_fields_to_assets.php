<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AutoElevate stage 3a: link an AutoElevate computer to a PSA asset. Mirrors the shape of
 * 2026_02_28_900000_add_controld_fields_to_assets.php (vendor id + vendor facts + synced_at).
 * Additive only — five nullable columns and one index; no backfill.
 *
 * PLAIN (non-unique) index on autoelevate_computer_id, deliberately — the same reason as
 * stage 2's clients.autoelevate_company_id. Assets are soft-deleted and a trashed asset keeps
 * its column values, so a unique index would let a soft-deleted asset's stale computer id
 * block the live replacement asset from ever being linked to that computer.
 * One-live-asset-per-computer is enforced by AutoElevateAssetSyncService instead.
 *
 * autoelevate_agent_version exists for shape parity with controld_agent_version, but the
 * Partner API 1.0.0 Computer schema carries NO agent-version field (verified 2026-09-22), so
 * the sync leaves it null rather than inventing a value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->uuid('autoelevate_computer_id')->nullable();
            $table->string('autoelevate_elevation_mode', 32)->nullable();
            $table->string('autoelevate_agent_version', 50)->nullable();
            $table->timestamp('autoelevate_last_checked_in_at')->nullable();
            $table->timestamp('autoelevate_synced_at')->nullable();
            $table->index('autoelevate_computer_id');
        });
    }

    public function down(): void
    {
        // Index first, in its own statement: SQLite rebuilds the table on a column drop and
        // re-validates surviving indexes (see the stage 2 clients migration).
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex('assets_autoelevate_computer_id_index');
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn([
                'autoelevate_computer_id',
                'autoelevate_elevation_mode',
                'autoelevate_agent_version',
                'autoelevate_last_checked_in_at',
                'autoelevate_synced_at',
            ]);
        });
    }
};
