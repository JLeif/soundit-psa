<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a time entry whose prepay debit was held because the client had
 * several active contracts and no default (card I3EvQKUV r1 diff:1), so that
 * choosing a default re-runs exactly those debits. Additive and nullable; no
 * backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['ticket_notes', 'phone_calls'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->timestamp('contract_held_at')->nullable()->after('contract_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['ticket_notes', 'phone_calls'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('contract_held_at');
            });
        }
    }
};
