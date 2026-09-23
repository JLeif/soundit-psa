<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('person_id');
            $table->string('holder');
            $table->timestamp('started_at');
            $table->unique(['client_id', 'person_id'], 'password_reset_claims_target_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_claims');
    }
};
