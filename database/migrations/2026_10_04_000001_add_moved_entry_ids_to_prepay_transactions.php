<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only contract moves for time entries (card I3EvQKUV PR 2, ruling Q4).
 * When an entry's time moves to another contract, its original debit row stays
 * on the old contract and only its entry link moves into these columns, beside
 * the credit row that reverses it. The unique ticket_note_id / phone_call_id
 * indexes keep meaning "the entry's current debit". No FK: the audit link
 * survives a hard-deleted entry. Additive, nullable, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prepay_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('moved_ticket_note_id')->nullable()->index();
            $table->unsignedBigInteger('moved_phone_call_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('prepay_transactions', function (Blueprint $table) {
            $table->dropIndex(['moved_ticket_note_id']);
            $table->dropIndex(['moved_phone_call_id']);
        });
        Schema::table('prepay_transactions', function (Blueprint $table) {
            $table->dropColumn(['moved_ticket_note_id', 'moved_phone_call_id']);
        });
    }
};
