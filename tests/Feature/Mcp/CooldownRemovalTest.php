<?php

namespace Tests\Feature\Mcp;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;
use App\Models\TechnicianActionLog;
use App\Services\Cipp\ResolvedCippPerson;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertSame(24, $fallbacks);
    }

    public function test_absent_tools_do_not_block_after_executed_rows_in_each_executor(): void
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
        foreach (['StaffCippAdmin', 'StaffCippWrite', 'StaffTacticalAdmin', 'StaffTacticalAction', 'StaffHuntressAction', 'StaffControlDOnboarding'] as $name) {
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
}
