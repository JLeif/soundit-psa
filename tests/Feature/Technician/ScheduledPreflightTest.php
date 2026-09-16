<?php

namespace Tests\Feature\Technician;

use App\Services\Technician\Scheduled\ScheduledClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class ScheduledPreflightTest extends TestCase
{
    use RefreshDatabase;

    private function clock(bool $healthy, bool $operational = true): void
    {
        $clock = Mockery::mock(ScheduledClock::class);
        $clock->shouldReceive('healthy')->times($operational ? 1 : 0)->andReturn($healthy);
        $this->app->instance(ScheduledClock::class, $clock);
        if ($operational) {
            $connection = Mockery::mock(DB::connection())->makePartial();
            $connection->shouldReceive('getDriverName')->andReturn('mariadb');
            DB::partialMock()->shouldReceive('connection')->andReturn($connection);
        }
    }

    public function test_empty_inventory_positive_and_unhealthy_negative(): void
    {
        $this->clock(true);
        $this->assertSame(0, Artisan::call('technician:scheduled-preflight'));
        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('healthy', $output['clock']);
        $this->assertSame(0, $output['total']);
        $this->assertFalse($output['activation_authorized']);
    }

    public function test_unhealthy_clock_never_certifies_empty_inventory(): void
    {
        $this->clock(false);
        $this->assertSame(1, Artisan::call('technician:scheduled-preflight'));
        $this->assertStringContainsString('unverified', Artisan::output());
    }

    public function test_fallback_driver_never_certifies_even_with_healthy_local_clock(): void
    {
        $this->clock(true, false);
        $this->assertSame(1, Artisan::call('technician:scheduled-preflight'));
        $this->assertStringContainsString('unverified', Artisan::output());
    }

    public function test_inventory_is_exhaustive_and_never_prints_stored_values(): void
    {
        $row = [
            'revision' => 1, 'client_id' => 1, 'ticket_id' => 1, 'approver_user_id' => 1,
            'action_type' => 'private-fixture', 'direct_tool' => 'private-fixture',
            'content_hash' => str_repeat('a', 64), 'digest' => str_repeat('b', 64),
            'target_key' => 'private-target', 'effect_key' => 'private-effect',
            'ciphertext' => 'private-ciphertext', 'approved_at' => now(), 'not_before' => now(),
            'expires_at' => now()->addHour(), 'display_timezone' => 'UTC',
            'local_start' => '2026-09-16 01:00:00', 'local_end' => '2026-09-16 02:00:00',
            'start_offset' => 0, 'end_offset' => 0, 'next_attempt_at' => now(),
        ];
        for ($i = 1; $i <= 151; $i++) {
            DB::table('scheduled_authorizations')->insert($row + ['run_id' => $i, 'state' => $i <= 150 ? 'dispatch_intent' : 'private-unknown-state']);
        }
        $this->clock(true);
        $this->assertSame(1, Artisan::call('technician:scheduled-preflight'));
        $text = Artisan::output();
        $output = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(151, $output['total']);
        $this->assertSame(150, $output['inventory']['dispatch_intent']);
        $this->assertSame(1, $output['inventory']['unknown']);
        $this->assertTrue($output['reconciliation_required']);
        $this->assertStringNotContainsString('private-', $text);
        $this->assertSame(151, DB::table('scheduled_authorizations')->count());
    }
}
