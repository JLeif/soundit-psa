<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent-owned asset watch alerts (card K3VEcxtw).
 *
 * owner is the calling staff MCP token's bare McpToken.label — the same key
 * signal_destinations.mcp_token_label and poll_signals use, so a fire lands in
 * the inbox only that token can drain.
 *
 * active_key enforces "one active watch per (owner, asset, state)" at the
 * database: it holds sha256(owner|asset_id|state) while the watch is active and
 * is NULLed when the watch is removed, expires or (one-shot) fires. Both SQLite
 * and MariaDB allow many NULLs under a unique index, so inactive rows never
 * collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_watches', function (Blueprint $table) {
            $table->id();
            $table->string('owner');
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('state', 10);
            $table->string('reason', 500);
            $table->boolean('repeat')->default(false);
            // dateTime, NOT timestamp. On MariaDB with explicit_defaults_for_timestamp
            // off (its default before 10.10), the first NOT NULL TIMESTAMP column with
            // no explicit default silently gains ON UPDATE CURRENT_TIMESTAMP, and every
            // observation updates this row, so each watch would lapse on its first
            // poll. CI is sqlite-only and cannot see it; same ruling as the
            // mesh_allow_rules migrations.
            $table->dateTime('expires_at');
            $table->timestamp('fired_at')->nullable();
            $table->unsignedInteger('fire_count')->default(0);
            $table->boolean('last_observed_state')->nullable();
            // When the newest applied observation was READ from Tactical, to the
            // microsecond; an older read arriving later is ignored (see
            // AssetWatchEvaluator). Whole seconds would let the sync's list read and
            // the poller's live read, both run every minute, tie and both apply.
            $table->dateTime('last_observed_at', 6)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->string('removed_reason', 500)->nullable();
            $table->char('active_key', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['owner', 'client_id']);
            $table->index(['asset_id', 'active_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_watches');
    }
};
