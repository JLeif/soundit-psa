<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_resolution_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_id')->constrained('emails')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('state')->default('pending')->index();
            $table->text('payload'); // encrypted sender + cohort + reason, never a message body
            $table->string('content_hash', 64);
            $table->unsignedInteger('email_count');
            $table->string('drafted_by');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_resolution_proposals');
    }
};
