<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prepay_transactions', function (Blueprint $table) {
            $table->unique('ticket_note_id');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            // Drop FK first: InnoDB may have dropped the auto-created FK index once
            // the unique index could serve the FK, and MariaDB won't drop an index
            // the FK still needs.
            Schema::table('prepay_transactions', function (Blueprint $table) {
                $table->dropForeign(['ticket_note_id']);
                $table->dropUnique(['ticket_note_id']);
            });

            Schema::table('prepay_transactions', function (Blueprint $table) {
                $table->foreign('ticket_note_id')->references('id')->on('ticket_notes')->nullOnDelete();
            });
        } else {
            Schema::table('prepay_transactions', function (Blueprint $table) {
                $table->dropUnique(['ticket_note_id']);
            });
        }
    }
};
