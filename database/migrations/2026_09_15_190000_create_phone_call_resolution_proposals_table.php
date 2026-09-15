<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_call_resolution_proposals', function (Blueprint $table) {
            $table->id();
            // Keep stale proposal evidence if a target is deleted; approval revalidates existence.
            $table->unsignedBigInteger('phone_call_id')->index();
            $table->unsignedBigInteger('client_id');
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
        Schema::dropIfExists('phone_call_resolution_proposals');
    }
};
