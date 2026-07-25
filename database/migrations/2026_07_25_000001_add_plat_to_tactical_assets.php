<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * psa-0pb9m — persist Tactical's own `plat` (windows|darwin|linux) per agent.
 * The platform is the anchor for checks-coverage truth on macOS endpoints and
 * for the create-check platform guard. Additive + nullable (MariaDB-safe):
 * populated by the daily list-sync and by syncDeviceDetail()/refresh-now;
 * rows synced before this migration fall back to an operating_system sniff
 * (TacticalPlatform::fromAgentPayload) until their next sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tactical_assets', function (Blueprint $table) {
            $table->string('plat', 16)->nullable()->after('os');
        });
    }

    public function down(): void
    {
        Schema::table('tactical_assets', function (Blueprint $table) {
            $table->dropColumn('plat');
        });
    }
};
