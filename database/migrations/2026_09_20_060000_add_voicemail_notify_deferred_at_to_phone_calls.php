<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two nullable markers for the voicemail staff email.
     *
     * `voicemail_notify_deferred_at` records that an email was WITHHELD because
     * the row carried no end evidence yet. It is cleared when that withheld
     * email is claimed, and it is not a record that anything was sent.
     *
     * `voicemail_notified_at` is that record, and it is the token both dispatch
     * paths in NotificationService claim before queueing. A cleared deferral
     * marker cannot be told apart from one that was never written, so on its own
     * it cannot stop a second sender reading the same row an instant later;
     * whichever path stamps this column owns the send.
     *
     * Additive and nullable: existing rows keep NULL in both columns, and no
     * backfill is performed or implied. A NULL `voicemail_notified_at` on a row
     * predating this change does NOT mean staff were never emailed about that
     * call — those rows were notified under the old behaviour, which left no
     * mark here.
     */
    public function up(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->timestamp('voicemail_notify_deferred_at')->nullable()->after('recording_duration');
            $table->timestamp('voicemail_notified_at')->nullable()->after('voicemail_notify_deferred_at');
        });
    }

    public function down(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->dropColumn(['voicemail_notify_deferred_at', 'voicemail_notified_at']);
        });
    }
};
