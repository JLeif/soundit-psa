<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('huntress_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_id', 255)->unique();
            $table->char('content_hash', 64);
            $table->string('event_type', 64);
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('record_type', 32);
            $table->unsignedBigInteger('record_id');
            $table->json('organization_ids');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->dateTime('record_created_at', 6);
            $table->dateTime('correlation_at', 6);
            $table->boolean('resolved');
            $table->dateTime('received_at', 6);
            $table->index(['record_type', 'record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('huntress_webhook_events');
    }
};
