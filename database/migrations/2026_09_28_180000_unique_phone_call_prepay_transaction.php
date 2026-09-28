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
            $table->unique('phone_call_id');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            // Drop FK first: InnoDB may have dropped the auto-created FK index once
            // the unique index could serve the FK, and MariaDB won't drop an index
            // the FK still needs.
            Schema::table('prepay_transactions', function (Blueprint $table) {
                $table->dropForeign(['phone_call_id']);
                $table->dropUnique(['phone_call_id']);
            });

            Schema::table('prepay_transactions', function (Blueprint $table) {
                $table->foreign('phone_call_id')->references('id')->on('phone_calls')->nullOnDelete();
            });
        } else {
            Schema::table('prepay_transactions', function (Blueprint $table) {
                $table->dropUnique(['phone_call_id']);
            });
        }
    }
};
