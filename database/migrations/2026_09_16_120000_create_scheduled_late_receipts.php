<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_late_receipts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('authorization_id')->index();
            $t->string('nonce', 36);
            $t->string('vendor', 16);
            $t->string('vendor_id')->nullable();
            $t->string('outcome', 24);
            $t->dateTime('intent_at', 6)->nullable();
            $t->dateTime('received_at', 6);
            // No cascading FK and no update/delete application path: retained evidence.
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain scheduled late-receipt evidence; destructive rollback is unsupported.');
    }
};
