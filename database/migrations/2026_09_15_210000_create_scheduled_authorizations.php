<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_authorizations', function (Blueprint $t) {
            $t->id();
            // Audit survives removal of the original ticket/user. No cascade deletes.
            $t->unsignedBigInteger('run_id');
            $t->unsignedInteger('revision');
            $t->unsignedBigInteger('client_id');
            $t->unsignedBigInteger('ticket_id');
            $t->unsignedBigInteger('approver_user_id');
            $t->unsignedBigInteger('originating_mcp_token_id')->nullable();
            $t->string('action_type', 100);
            $t->string('direct_tool', 100);
            $t->string('content_hash', 64);
            $t->unsignedInteger('schema_version')->default(1);
            $t->longText('ciphertext')->nullable();
            $t->string('digest', 64);
            $t->string('target_key', 64);
            $t->string('effect_key', 64);
            $t->dateTime('approved_at', 6);
            $t->dateTime('not_before', 6);
            $t->dateTime('expires_at', 6);
            $t->string('display_timezone', 64);
            $t->string('local_start', 19);
            $t->string('local_end', 19);
            $t->integer('start_offset');
            $t->integer('end_offset');
            $t->string('state', 24)->default('waiting');
            $t->unsignedInteger('attempt')->default(0);
            $t->unsignedInteger('transition_sequence')->default(0);
            $t->dateTime('next_attempt_at', 6);
            $t->string('nonce', 36)->nullable();
            $t->dateTime('claimed_at', 6)->nullable();
            $t->dateTime('intent_at', 6)->nullable();
            $t->dateTime('finished_at', 6)->nullable();
            $t->dateTime('purged_at', 6)->nullable();
            $t->string('reason', 64)->nullable();
            $t->unique(['run_id', 'revision']);
            $t->index(['state', 'next_attempt_at', 'not_before']);
        });
        Schema::create('scheduled_run_fences', function (Blueprint $t) {
            $t->unsignedBigInteger('run_id')->primary();
            $t->unsignedBigInteger('authorization_id')->unique();
        });
        Schema::create('scheduled_target_fences', function (Blueprint $t) {
            $t->string('target_key', 64)->primary();
            $t->unsignedBigInteger('authorization_id')->unique();
        });
        Schema::create('scheduled_note_outbox', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('authorization_id');
            $t->unsignedInteger('transition_sequence');
            $t->string('event', 32);
            $t->string('reason', 64)->nullable();
            $t->dateTime('created_at', 6);
            $t->unsignedBigInteger('note_id')->nullable();
            $t->string('delivery_error', 32)->nullable();
            $t->unique(['authorization_id', 'transition_sequence'], 'scheduled_note_transition_unique');
        });
    }

    public function down(): void
    {
        // Refuse destructive rollback over retained authorization/audit evidence.
        if (\Illuminate\Support\Facades\DB::table('scheduled_authorizations')->exists()) {
            throw new RuntimeException('Retained scheduled authorization evidence: disable feature; do not drop tables.');
        }
        Schema::dropIfExists('scheduled_note_outbox');
        Schema::dropIfExists('scheduled_target_fences');
        Schema::dropIfExists('scheduled_run_fences');
        Schema::dropIfExists('scheduled_authorizations');
    }
};
