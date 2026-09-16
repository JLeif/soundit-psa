<?php

namespace Tests\Feature\Api;

use App\Enums\AlertSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /api/rmm/alerts and /api/rmm/alerts/resolve — the write surface Leif RMM
 * uses to report coverage drift.
 *
 * The RMM watches whether each client's machines still have what that client is
 * supposed to have (Huntress, Control D, our own agent). When a machine loses
 * coverage, something has to say so. These two endpoints are that channel, and
 * they deliberately raise ALERTS rather than tickets: the PSA's alert pipeline
 * already dedupes on source + source_alert_id, counts re-fires, and can be
 * promoted to a ticket by a human from the alerts screen. A ticket is a
 * commitment to bill and to work; an automatic one per transition is how a queue
 * becomes noise.
 *
 * The refusals matter as much as the happy path. An alert filed against the
 * wrong client is worse than no alert at all.
 */
class RmmAlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_leif_rmm_is_a_known_alert_source(): void
    {
        $this->assertSame('leif_rmm', AlertSource::LeifRmm->value);
        $this->assertSame('Leif RMM', AlertSource::LeifRmm->label());
    }
}
