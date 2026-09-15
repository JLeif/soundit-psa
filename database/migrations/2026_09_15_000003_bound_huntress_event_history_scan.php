<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('huntress_link_candidates', function (Blueprint $table) {
            $table->unsignedBigInteger('event_scan_after_id')->default(0);
            $table->index('alert_id', 'huntress_candidate_alert');
        });
        Schema::table('huntress_webhook_events', function (Blueprint $table) {
            $table->index(['record_type', 'record_id', 'id'], 'huntress_event_scan');
        });
    }

    public function down(): void
    {
        Schema::table('huntress_link_candidates', function (Blueprint $table) {
            $table->dropIndex('huntress_candidate_alert');
            $table->dropColumn('event_scan_after_id');
        });
        Schema::table('huntress_webhook_events', function (Blueprint $table) {
            $table->dropIndex('huntress_event_scan');
        });
    }
};
