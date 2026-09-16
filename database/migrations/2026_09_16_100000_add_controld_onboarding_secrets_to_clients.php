<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Ciphertext has variable overhead; do not impose a vendor-code length guess.
            $table->text('controld_provisioning_code')->nullable();
            $table->text('controld_deactivation_pin')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('clients')->whereNotNull('controld_provisioning_code')
            ->orWhereNotNull('controld_deactivation_pin')->exists()) {
            throw new RuntimeException('Refusing to drop populated Control D onboarding secrets; reconcile retention first.');
        }
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['controld_provisioning_code', 'controld_deactivation_pin']);
        });
    }
};
