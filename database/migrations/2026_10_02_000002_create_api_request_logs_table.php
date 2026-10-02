<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20)->default('request');
            $table->foreignId('api_token_id')->nullable()->constrained('api_tokens')->nullOnDelete();
            $table->string('endpoint', 100)->nullable();
            $table->string('method', 10)->nullable();
            $table->string('path', 255)->nullable();
            $table->unsignedSmallInteger('status')->nullable();
            $table->string('cause', 32)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('source_ip', 45)->nullable();
            $table->string('actor', 191)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['api_token_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
    }
};
