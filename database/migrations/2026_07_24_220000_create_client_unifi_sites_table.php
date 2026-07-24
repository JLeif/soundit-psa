<?php

use App\Models\ClientUnifiSite;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * psa-jpygj: UniFi client->site becomes one-to-MANY. A client with several
 * physical locations maps to several UniFi sites. Site->client stays <=1
 * (the UNIQUE on unifi_site_id is preserved on the pivot). The legacy one-to-one
 * clients.unifi_site_id/unifi_host_id columns are backfilled into this pivot and
 * remain (nullable) during the transition; a later increment drops them once
 * every reader uses the pivot.
 *
 * Ids are opaque strings (siteId hex; hostId can embed a colon), matching the
 * 2026_07_23 add_unifi_mapping_to_clients migration this supersedes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_unifi_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('unifi_site_id')->unique();   // a site maps to <=1 client
            $table->string('unifi_host_id')->nullable(); // owning console
            $table->timestamps();
            $table->index('client_id');
        });

        // Carry existing per-client mappings over with no loss (idempotent).
        ClientUnifiSite::backfillFromLegacyColumns();
    }

    public function down(): void
    {
        Schema::dropIfExists('client_unifi_sites');
    }
};
