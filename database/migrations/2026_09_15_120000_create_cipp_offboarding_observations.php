<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cipp_offboarding_observations', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_id');
            $table->foreign('operation_id')->references('id')->on('cipp_offboarding_operations')->restrictOnDelete();
            $table->foreignId('observer_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('prior_observation_id')->nullable();
            $table->text('observation'); // Encrypted bound correlation and sanitized report; append only.
            $table->boolean('conflict')->default(false);
            $table->timestamp('started_at');
            $table->timestamp('created_at');
            $table->index(['operation_id', 'id']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('cipp_offboarding_observations') && DB::table('cipp_offboarding_observations')->exists()) {
            throw new RuntimeException('Retain offboarding reconciliation evidence; disable code instead.');
        }
        Schema::dropIfExists('cipp_offboarding_observations');
    }
};
