<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $key = DB::getDriverName() === 'sqlite'
            ? "content_hash || ':' || drafted_by"
            : "CONCAT(content_hash, ':', drafted_by)";

        Schema::table('time_entry_move_proposals', function (Blueprint $table) use ($key) {
            $table->string('pending_key', 320)->nullable()
                ->virtualAs("CASE WHEN state = 'pending' THEN {$key} ELSE NULL END");
            $table->unique('pending_key', 'time_entry_move_pending_key_unique');
            $table->unsignedBigInteger('activity_watermark')->nullable();
            $table->index('activity_watermark', 'time_entry_move_activity_watermark_index');
        });
    }

    public function down(): void
    {
        Schema::table('time_entry_move_proposals', function (Blueprint $table) {
            $table->dropUnique('time_entry_move_pending_key_unique');
            $table->dropIndex('time_entry_move_activity_watermark_index');
            $table->dropColumn(['pending_key', 'activity_watermark']);
        });
    }
};
