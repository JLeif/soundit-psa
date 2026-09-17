<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AutoElevate stage 2: per-client company mapping, mirroring huntress_organization_id /
 * controld_org_id. Additive only — one nullable, indexed column; no backfill, no other
 * table. AutoElevate company ids are UUID strings (Company.id, format uuid, in the
 * vendor's Partner API OpenAPI), never integers.
 *
 * Plain (non-unique) index: the mapping controller enforces one client per company in
 * application code (clear-then-apply), the same way the Huntress screen does, and a
 * unique index would make a soft-deleted client's stale mapping block re-mapping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->uuid('autoelevate_company_id')->nullable()->after('controld_org_id');
            $table->index('autoelevate_company_id');
        });
    }

    public function down(): void
    {
        // Index first, in its own statement: SQLite rebuilds the table on a column drop
        // and re-validates surviving indexes (see the unifi mapping migration).
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex('clients_autoelevate_company_id_index');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('autoelevate_company_id');
        });
    }
};
