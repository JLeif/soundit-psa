<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Independent "accepted" count for the contact-intake reconciliation (card qmcqiE7s).
 * SubmissionLedger increments it in the same transaction as a first-time ledger insert,
 * so the staff index can compare it with the ledger's rows by state. Counting the
 * rows themselves could never disagree with the rows.
 *
 * No backfill: production had 0 contact_submissions rows when this was written
 * (measured 2026-09-28 at 782ed66a). The counter starts at 0. On a database that
 * already holds rows, the staff index reports the difference as a mismatch; it does not
 * invent a total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_intake_counters', function (Blueprint $table) {
            $table->string('name', 32)->primary();
            $table->unsignedBigInteger('total')->default(0);
        });
        DB::table('contact_intake_counters')->insert(['name' => 'accepted', 'total' => 0]);
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_intake_counters');
    }
};
