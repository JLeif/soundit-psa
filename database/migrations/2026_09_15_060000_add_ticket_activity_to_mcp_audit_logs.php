<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_audit_logs', function (Blueprint $table): void {
            $table->unsignedBigInteger('ticket_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('action_log_id')->nullable()->index();
            $table->string('correlation_id')->nullable();
            $table->string('activity_kind', 20)->nullable();
            $table->string('result_summary', 500)->nullable();
            $table->index(['ticket_id', 'client_id', 'id'], 'mcp_ticket_activity_page');
        });
    }

    public function down(): void
    {
        Schema::table('mcp_audit_logs', function (Blueprint $table): void {
            $table->dropIndex('mcp_ticket_activity_page');
            $table->dropIndex(['action_log_id']);
            $table->dropColumn(['ticket_id', 'client_id', 'action_log_id', 'correlation_id', 'activity_kind', 'result_summary']);
        });
    }
};
