<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operator_inbox', function (Blueprint $table) {
            // Card 2Cj3kOsy. Additive and nullable, no backfill: NULL means the
            // row predates attachment capture (unknown), never "no attachments".
            // attachments holds our metadata refs only (kind, ordinal, sanitized
            // filename), never URLs or bytes. activity_id is the Bot Framework
            // activity id, which for a Teams chat message is the Graph message id
            // get_teams_message_attachment resolves the ref through.
            $table->json('attachments')->nullable();
            $table->string('activity_id', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('operator_inbox', function (Blueprint $table) {
            $table->dropColumn(['attachments', 'activity_id']);
        });
    }
};
