<?php

namespace Tests\Feature\Mcp;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;
use App\Models\TechnicianActionLog;
use App\Models\Ticket;
use App\Services\Cipp\ResolvedCippPerson;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

class CooldownRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_executor_cooldown_is_zero_except_the_named_reset(): void
    {
        $maps = $constants = $fallbacks = 0;
        foreach (glob(app_path('Services/Mcp/*Executor.php')) as $file) {
            $source = file_get_contents($file);
            $class = new ReflectionClass('App\\Services\\Mcp\\'.basename($file, '.php'));
            if ($class->hasConstant('COOLDOWNS')) {
                $maps++;
                foreach ($class->getConstant('COOLDOWNS') as $tool => $seconds) {
                    // zAYpGMFJ: B2 must delete this sole exception after target-wide intent coordination.
                    $expected = $tool === 'cipp_reset_user_password' ? 300 : 0;
                    $this->assertSame($expected, $seconds, $tool);
                }
            }
            if ($class->hasConstant('COOLDOWN_SECONDS')) {
                $constants++;
                $this->assertSame(0, $class->getConstant('COOLDOWN_SECONDS'), $class->name);
            }
            preg_match_all('/COOLDOWNS\[[^\]]+\]\s*\?\?\s*(\d+)/', $source, $matches);
            foreach ($matches[1] as $seconds) {
                $fallbacks++;
                $this->assertSame('0', $seconds, $file);
            }
        }
        // Positive corpus controls: an empty/wrong source scan cannot pass.
        $this->assertSame(4, $maps);
        $this->assertSame(2, $constants);
        // Reset staging passes literal 0 instead of consulting the live reset map entry.
        $this->assertSame(23, $fallbacks);
    }

    public function test_published_definitions_only_name_the_three_accurate_cooldowns(): void
    {
        // The six B1 timer-owning executors, not refusal strings or the unrelated Mesh catalog.
        $mentions = [];
        $definitions = [];
        foreach (self::executors() as [$name]) {
            $class = 'App\\Services\\Mcp\\'.$name.'ToolExecutor';
            foreach ($class::definitions() as $definition) {
                $definitions[$definition['name']] = $definition['description'];
                if (stripos($definition['description'], 'cooldown') !== false) {
                    $mentions[] = $definition['name'];
                }
            }
        }
        // Live positive controls over the same OUTPUT: not an empty scan or a source-text pin.
        $this->assertCount(125, $definitions);
        foreach (['tactical_run_command', 'cipp_set_mailbox_delegate', 'cipp_reset_user_password'] as $name) {
            $this->assertStringContainsString('cooldown', $definitions[$name], $name);
        }
        sort($mentions);
        $this->assertSame(['cipp_reset_user_password', 'cipp_set_mailbox_delegate', 'tactical_run_command'], $mentions);
        $this->assertStringContainsString('execution/approval, not at staging', $definitions['cipp_reset_user_password']);
        $this->assertStringContainsString('300 seconds per target', $definitions['cipp_reset_user_password']);
        $this->assertStringContainsString('seconds left', $definitions['cipp_reset_user_password']);
        $this->assertStringContainsString('no rate bound on back-to-back runs', $definitions['cipp_sync_people_now']);
    }

    public function test_other_helpers_ignore_same_instant_rows_at_zero_with_live_query_controls(): void
    {
        $this->freezeTime();
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->for($client)->create();
        $person = Person::create(['client_id' => $client->id, 'first_name' => 'Test', 'last_name' => 'Person', 'email' => 'test@example.test', 'person_type' => 'user', 'is_active' => true]);
        $resolved = new ResolvedCippPerson($person, 'test-user', 'test@example.test');
        $tool = 'cipp_assign_tenant_user_license';
        $target = 'person #'.$person->id;
        $hash = hash('sha256', 'same-instant');
        TechnicianActionLog::create(['action_type' => $tool, 'client_id' => $client->id,
            'ticket_id' => $ticket->id, 'actor_label' => 'test', 'result_status' => 'executed',
            'summary' => $target.': completed', 'tier' => 'auto', 'content_hash' => $hash, 'correlation_id' => 'test']);
        $cases = [
            ['StaffCippWrite', 'proposalCooldownActive', [$tool, $ticket, $resolved, null]],
            ['StaffCippWrite', 'emailSecurityCooldownActive', [$tool, $client->id, $target]],
            ['StaffCippWrite', 'emailSecurityProposalCooldownActive', [$tool, $ticket, $target]],
            ['StaffCippWrite', 'licenseTargetCooldownActive', [$tool, $client->id, $target]],
            ['StaffTacticalAction', 'proposalCooldownActive', [$tool, $ticket]],
            ['StaffTacticalAdmin', 'cooldownActiveForContent', [$tool, $client->id, $hash]],
        ];
        foreach ($cases as [$name, $method, $args]) {
            $class = new ReflectionClass('App\\Services\\Mcp\\'.$name.'ToolExecutor');
            $executor = $class->newInstanceWithoutConstructor();
            $query = $class->getMethod($method);
            $this->assertTrue($query->invokeArgs($executor, [...$args, 300]), $name.'::'.$method.' live positive control');
            $this->assertFalse($query->invokeArgs($executor, [...$args, 0]), $name.'::'.$method.' zero');
        }
    }

    public function test_reset_rounding_expiry_and_blocked_rows_do_not_rearm(): void
    {
        $start = Carbon::parse('2026-01-01 12:00:00');
        $this->travelTo($start);
        $client = Client::factory()->create();
        $person = Person::create(['client_id' => $client->id, 'first_name' => 'Test', 'last_name' => 'Person', 'email' => 'test@example.test', 'person_type' => 'user', 'is_active' => true]);
        $resolved = new ResolvedCippPerson($person, 'test-user', 'test@example.test');
        $row = ['action_type' => 'cipp_reset_user_password', 'client_id' => $client->id,
            'actor_label' => 'test', 'result_status' => 'executed', 'summary' => 'person #'.$person->id.': completed',
            'tier' => 'auto', 'content_hash' => hash('sha256', 'reset'), 'correlation_id' => 'test'];
        TechnicianActionLog::create($row);
        $class = new ReflectionClass('App\\Services\\Mcp\\StaffCippWriteToolExecutor');
        $executor = $class->newInstanceWithoutConstructor();
        $query = $class->getMethod('resetCooldownRemainingSeconds');
        $remaining = fn () => $query->invoke($executor, $client->id, $resolved, 300);
        $this->assertSame(300, $remaining(), 'live executed-row positive control');
        $this->travelTo($start->copy()->addSeconds(299)->addMicroseconds(400000));
        TechnicianActionLog::create(array_replace($row, ['result_status' => 'blocked']));
        $this->assertSame(1, $remaining(), '0.6 seconds rounds UP; blocked row does not re-arm');
        $this->travelTo($start->copy()->addSeconds(300));
        $this->assertSame(0, $remaining(), 'exact expiry is allowed despite inclusive query boundary');
        $this->travelTo($start->copy()->addSeconds(301));
        $this->assertSame(0, $remaining(), 'blocked row cannot extend the expired window');
        TechnicianActionLog::create(array_replace($row, ['summary' => 'person #'.$person->id.'0: different target']));
        $this->assertSame(0, $remaining(), 'target-prefix collision does not arm the window');
        TechnicianActionLog::create(array_replace($row, ['action_type' => 'cipp_stage_reset_user_password']));
        $this->assertSame(300, $remaining(), 'fresh held execution is a live positive control at the final time');
    }

    public static function executors(): array
    {
        return array_map(fn ($name) => [$name], ['StaffCippAdmin', 'StaffCippWrite', 'StaffTacticalAdmin', 'StaffTacticalAction', 'StaffHuntressAction', 'StaffControlDOnboarding']);
    }

    #[DataProvider('executors')]
    public function test_absent_tools_do_not_block_after_executed_rows_in_each_executor(string $name): void
    {
        $this->freezeTime();
        $client = Client::factory()->create();
        $person = Person::create(['client_id' => $client->id, 'first_name' => 'Test', 'last_name' => 'Person', 'email' => 'test@example.test', 'person_type' => 'user', 'is_active' => true]);
        $resolved = new ResolvedCippPerson($person, 'test-user', 'test@example.test');
        $asset = Asset::factory()->create(['client_id' => $client->id]);
        $tool = 'absent_cooldown_test_tool';
        $target = 'person #'.$person->id;
        TechnicianActionLog::create([
            'action_type' => $tool, 'client_id' => $client->id, 'actor_label' => 'test',
            'result_status' => 'executed', 'summary' => $target.': completed',
            'tier' => 'auto', 'content_hash' => hash('sha256', 'test'), 'correlation_id' => 'test',
        ]);
        $class = new ReflectionClass('App\\Services\\Mcp\\'.$name.'ToolExecutor');
        $map = $class->hasConstant('COOLDOWNS') ? $class->getConstant('COOLDOWNS') : [];
        $this->assertArrayNotHasKey($tool, $map);
        $source = file_get_contents($class->getFileName());
        preg_match_all('/COOLDOWNS\[[^\]]+\]\s*\?\?\s*(\d+)/', $source, $matches);
        // Exercise each actual source fallback, not a duplicated hardcoded zero.
        // CippAdmin has direct indexing only (no production absent-key path);
        // constant families have no map, so use their actual shared constant.
        $defaults = $matches[1] ?: [$class->hasConstant('COOLDOWN_SECONDS') ? $class->getConstant('COOLDOWN_SECONDS') : max($map)];
        $executor = $class->newInstanceWithoutConstructor();
        $method = $class->getMethod('cooldownActive');
        $args = match ($name) {
            'StaffCippWrite' => [$tool, $client->id, $resolved, null],
            'StaffTacticalAction' => [$tool, $asset, null],
            'StaffHuntressAction', 'StaffControlDOnboarding' => [[$tool], $client->id, $target],
            default => [$tool, $client->id],
        };
        // A real matching executed row must arm the SAME query with a nonzero window.
        $this->assertTrue($method->invokeArgs($executor, [...$args, 300]), $name.' positive control');
        foreach ($defaults as $default) {
            $seconds = $map[$tool] ?? (int) $default;
            $this->assertFalse($method->invokeArgs($executor, [...$args, $seconds]), $name.' absent map');
        }
        if ($class->hasMethod('executedCooldownActive')) {
            $held = $class->getMethod('executedCooldownActive');
            $this->assertTrue($held->invokeArgs($executor, [...$args, 300]));
            $this->assertFalse($held->invokeArgs($executor, [...$args, $class->getConstant('COOLDOWN_SECONDS')]));
        }
    }
}
