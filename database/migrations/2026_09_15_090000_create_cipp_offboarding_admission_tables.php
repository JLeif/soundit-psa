<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cipp_offboarding_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('staged_run_id')->unique()->constrained('technician_runs')->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $table->char('plan_hash', 64);
            $table->unsignedInteger('revision');
            $table->text('snapshot');
            $table->string('reference', 100)->unique();
            $table->string('admission', 32)->default('prepared');
            $table->foreignId('approver_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at');
            $table->uuid('dispatch_generation')->nullable();
            $table->timestamp('send_intent_at')->nullable();
            $table->char('payload_digest', 64)->nullable();
            $table->string('response_class', 40)->nullable();
            $table->char('response_digest', 64)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('receipt')->nullable();
            $table->timestamps();
        });
        Schema::create('cipp_offboarding_target_fences', function (Blueprint $table) {
            // HMAC namespace keys include installation/integration/canonical tenant,
            // never ticket or requester. Separate immutable-id AND UPN reservations.
            $table->char('fence_key', 64)->primary();
            $table->uuid('operation_id');
            $table->foreign('operation_id')->references('id')->on('cipp_offboarding_operations')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('cipp_offboarding_spent_plans', function (Blueprint $table) {
            $table->char('plan_key', 64)->primary();
            $table->uuid('operation_id');
            $table->foreign('operation_id')->references('id')->on('cipp_offboarding_operations')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('cipp_offboarding_audit', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_id');
            $table->foreign('operation_id')->references('id')->on('cipp_offboarding_operations')->restrictOnDelete();
            $table->string('event', 40);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        // Rollback is a code disable, NOT erasure of evidence or a vendor cancellation.
        if (Schema::hasTable('cipp_offboarding_operations') && DB::table('cipp_offboarding_operations')->exists()) {
            throw new RuntimeException('Offboarding admission evidence exists; retain tables and disable admission instead.');
        }
        Schema::dropIfExists('cipp_offboarding_audit');
        Schema::dropIfExists('cipp_offboarding_spent_plans');
        Schema::dropIfExists('cipp_offboarding_target_fences');
        Schema::dropIfExists('cipp_offboarding_operations');
    }
};
