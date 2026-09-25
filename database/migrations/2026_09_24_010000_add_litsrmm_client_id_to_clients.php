<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the per-client mapping column for the LITSRMM integration.
 *
 * Column name and width follow the sibling RMM mappings (level_group_id,
 * ninja_org_id): string(64), nullable, NOT unique.
 *
 * Not unique to match THE RMM SIBLINGS specifically: level_group_id,
 * ninja_org_id and comet_group_id carry no unique index either. The wider
 * claim this comment used to make — that a unique index would be stricter
 * than all eleven vendors beside it — was false, and is corrected here rather
 * than left to support a conclusion it does not: eight of the eleven
 * (mesh_customer_id, cipp_tenant_domain, huntress_organization_id,
 * servosity_company_id, controld_org_id, zorus_customer_id,
 * stripe_customer_id, qbo_customer_id) ARE unique.
 *
 * So the one-client-per-entity rule rests on ClientIntegrationService's link
 * path, which enforces it in application code for every vendor. That is a
 * weaker guarantee than an index, and a write that bypasses the link path is
 * not refused. Tracked, not assumed away.
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
