<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LITSRMM stage 3 review: litsrmm_retired_at marks an asset the device sync
 * made inactive because its device was retired, so the change can be undone
 * when the same machine comes back, and is never confused with an asset a
 * person made inactive. Additive: one nullable column, no backfill.
 *
 * Its own migration rather than a change to
 * 2026_09_30_120000_add_litsrmm_fields_to_assets.php, which has already run
 * on a live database and would never run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('assets', 'litsrmm_retired_at')) {
            return;
        }

        Schema::table('assets', function (Blueprint $table) {
            $table->timestamp('litsrmm_retired_at')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('assets', 'litsrmm_retired_at')) {
            return;
        }

        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('litsrmm_retired_at');
        });
    }
};
