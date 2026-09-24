<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the per-client mapping column for the LITSRMM integration.
 *
 * Column name and width follow the sibling RMM mappings (level_group_id,
 * ninja_org_id): string(64), nullable, NOT unique. Not unique deliberately —
 * ClientIntegrationService's link path enforces the one-client-per-entity rule
 * in application code for every vendor, and a unique index here would be a
 * twelfth vendor quietly holding a stricter contract than the eleven beside it.
 *
 * down() drops only the column this migration added. Safe to reverse: the
 * column carries operator-entered mappings, so a rollback loses those mappings
 * and nothing else, and re-running up() restores an empty column rather than
 * wrong data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('litsrmm_client_id', 64)->nullable()->after('level_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('litsrmm_client_id');
        });
    }
};
