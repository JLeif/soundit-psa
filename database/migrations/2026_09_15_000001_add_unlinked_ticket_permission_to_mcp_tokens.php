<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('mcp_tokens', 'allow_unlinked_tickets')) {
            Schema::table('mcp_tokens', function (Blueprint $table) {
                $table->boolean('allow_unlinked_tickets')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mcp_tokens', 'allow_unlinked_tickets')) {
            Schema::table('mcp_tokens', function (Blueprint $table) {
                $table->dropColumn('allow_unlinked_tickets');
            });
        }
    }
};
