<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('controld_onboarding_intents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('client_id')->index();
            $table->unsignedBigInteger('actor_id');
            // A durable, non-expiring per-client mutex. NULL only after a known terminal
            // outcome. No FK cascade: deletion must not erase uncertain-write evidence.
            $table->unsignedBigInteger('active_client_id')->nullable()->unique();
            $table->string('operation', 20);
            $table->string('state', 20);
            $table->string('phase', 30);
            $table->text('payload');
            $table->string('org_pk', 255)->nullable();
            $table->string('vendor_pk', 255)->nullable();
            $table->integer('reason_code')->nullable();
            $table->string('reason', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('controld_onboarding_intents')->exists()) {
            throw new RuntimeException('Retain populated Control D intent evidence; rollback callers only.');
        }
        Schema::dropIfExists('controld_onboarding_intents');
    }
};
