<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marker for a voicemail notification that was withheld because the row
     * carried no end evidence yet.
     *
     * Additive and nullable: existing rows keep NULL, which reads as "nothing
     * was withheld for this call". It deliberately does NOT mean "notified" —
     * a sent notification leaves no mark here at all. The column answers one
     * question only: is there a withheld voicemail email waiting for this call
     * to gain an ended_at.
     */
    public function up(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->timestamp('voicemail_notify_deferred_at')->nullable()->after('recording_duration');
        });
    }

    public function down(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->dropColumn('voicemail_notify_deferred_at');
        });
    }
};
