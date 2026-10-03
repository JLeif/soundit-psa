<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #5067 r3: billable minutes added to time_minutes when the note's time is priced for
 * prepay (TicketNote::pricedMinutes()). Set only by prepay:relink-halo-ticket-time, from
 * Halo's timetakenAdjusted, or negative where Halo drew only part of the action from prepay
 * (prepayHours - timetaken); a native note leaves it NULL, which prices as 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_notes', function (Blueprint $table) {
            $table->integer('time_adjustment_minutes')->nullable()->after('time_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_notes', function (Blueprint $table) {
            $table->dropColumn('time_adjustment_minutes');
        });
    }
};
