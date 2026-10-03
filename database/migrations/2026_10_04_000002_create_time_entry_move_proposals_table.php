<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Held agent moves of a time entry's contract (card I3EvQKUV PR 2, ruling Q9):
 * stage_move_time_entry_contract writes one pending row; a staff user approves
 * or denies it in the technician cockpit, and approval re-validates before any money
 * moves. No FKs on the entry: stale evidence survives a deleted target, and
 * approval re-checks existence. Additive and reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entry_move_proposals', function (Blueprint $table) {
            $table->id();
            $table->string('entry_type', 10);
            $table->unsignedBigInteger('entry_id');
            $table->unsignedBigInteger('ticket_id')->index();
            $table->unsignedBigInteger('from_contract_id')->nullable();
            $table->unsignedBigInteger('to_contract_id');
            $table->text('reason');
            $table->string('content_hash', 64)->index();
            $table->string('state')->default('pending')->index();
            $table->string('drafted_by');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
            $table->index(['entry_type', 'entry_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entry_move_proposals');
    }
};
