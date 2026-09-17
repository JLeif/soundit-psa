<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Held proposals for the agent-side call-log writes whose effect is money
        // (set_call_billable re-runs the prepay debit) or client-visible phone
        // routing (block_caller / allow_caller silences or rings a real number).
        // Mirrors phone_call_resolution_proposals deliberately: same shape, same
        // "keep the evidence, revalidate at approval" contract, one extra
        // action_type column because ONE table serves three capabilities.
        Schema::create('phone_call_action_proposals', function (Blueprint $table) {
            $table->id();
            // No FK: stale proposal evidence survives a deleted target; approval
            // revalidates existence rather than trusting the row.
            $table->unsignedBigInteger('phone_call_id')->index();
            $table->string('action_type')->index();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->text('payload');
            $table->string('content_hash', 64)->index();
            $table->string('state')->default('pending')->index();
            $table->string('drafted_by');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_call_action_proposals');
    }
};
