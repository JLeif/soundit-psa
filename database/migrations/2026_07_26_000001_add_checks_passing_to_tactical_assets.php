<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * psa-0pb9m (revise) — persist the vendor's explicit `passing` check count per
 * agent. Coverage "verified" now requires explicit passing evidence, never
 * failing < total (that gap can be pending / never-reporting / warning-severity
 * failures). Additive + nullable (MariaDB-safe): populated by the device sync
 * from the agent `checks` summary dict; rows synced before this migration have
 * NULL and classify as coverage "unknown" (never verified-by-default) until
 * their next sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tactical_assets', function (Blueprint $table) {
            $table->unsignedInteger('checks_passing')->nullable()->after('checks_failing');
        });
    }

    public function down(): void
    {
        Schema::table('tactical_assets', function (Blueprint $table) {
            $table->dropColumn('checks_passing');
        });
    }
};
