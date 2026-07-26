<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * psa-0pb9m R3 (A5): `shell` is a platform-compatibility signal consumed by
 * the check-creation guard. A script row whose upstream getScripts entry
 * carries no shell must be stored as NULL (unknown — the guard fails closed
 * on it), never silently defaulted to 'powershell': that default turned a
 * drifted vendor response into a usable compatibility verdict.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tactical_scripts', function (Blueprint $table) {
            $table->string('shell', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        // IRREVERSIBLE BY DESIGN — this is an explicit no-op, not a revert
        // (psa-0pb9m R4). Restoring NOT NULL would need a value for every row
        // whose upstream getScripts entry carries no shell, and inventing one
        // ('powershell') is exactly the manufactured-signal defect this
        // migration removes. Rolling back therefore leaves the column
        // nullable; stated here so the rollback does not merely LOOK
        // reversible while restoring nothing.
    }
};
