<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A pre-existing singleton serializes both arrival paths, including the
        // no-event/no-candidate case where SELECT FOR UPDATE locks no rows.
        Schema::create('huntress_link_mutex', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('version')->default(0);
        });
        DB::table('huntress_link_mutex')->insert(['id' => 1]);
        Schema::create('huntress_link_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('record_type', 32)->nullable();
            $table->unsignedBigInteger('record_id')->nullable();
            $table->unsignedBigInteger('candidate_org_id')->nullable();
            $table->string('capture_refusal', 64)->nullable();
            $table->dateTime('received_at', 6);
            $table->index(['record_type', 'record_id', 'organization_id'], 'huntress_candidate_record');
        });
        Schema::table('alerts', function (Blueprint $table) {
            $table->unsignedBigInteger('huntress_account_id')->nullable();
            $table->unsignedBigInteger('huntress_org_id')->nullable();
            $table->string('huntress_record_type', 32)->nullable();
            $table->unsignedBigInteger('huntress_record_id')->nullable();
            $table->foreignId('huntress_event_id')->nullable()->constrained('huntress_webhook_events');
            $table->dateTime('huntress_linked_at', 6)->nullable();
            $table->string('huntress_link_refusal', 64)->nullable();
            $table->unique(['huntress_record_type', 'huntress_record_id', 'huntress_org_id'], 'huntress_validated_record_org');
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropForeign(['huntress_event_id']);
            $table->dropUnique('huntress_validated_record_org');
            $table->dropColumn(['huntress_account_id', 'huntress_org_id', 'huntress_record_type', 'huntress_record_id', 'huntress_event_id', 'huntress_linked_at', 'huntress_link_refusal']);
        });
        Schema::dropIfExists('huntress_link_candidates');
        Schema::dropIfExists('huntress_link_mutex');
    }
};
