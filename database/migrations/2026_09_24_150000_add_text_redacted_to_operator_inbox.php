<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operator_inbox', function (Blueprint $table) {
            // No backfill/default: historical ingest redaction is unknown.
            $table->boolean('text_redacted')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('operator_inbox', function (Blueprint $table) {
            $table->dropColumn('text_redacted');
        });
    }
};
