<?php

namespace Tests\Feature\Technician;

use App\Models\Setting;
use App\Models\User;
use App\Support\TechnicianConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScheduledSettingsTest extends TestCase
{
    use RefreshDatabase;

    public static function values(): array
    {
        return [[null, false], ['0', false], ['yes', false], ['true', false], ['1', true]];
    }

    #[DataProvider('values')]
    public function test_setting_is_strict_and_preflight_reports_operator_choice(?string $value, bool $expected): void
    {
        Http::preventStrayRequests();
        if ($value !== null) {
            Setting::setValue('scheduled_approvals_enabled', $value);
        }
        // A stale config override cannot act as a second switch in either direction.
        config(['scheduled_approvals.enabled' => ! $expected]);
        $this->assertSame($expected, TechnicianConfig::scheduledApprovalsEnabled());
        $this->assertSame(1, Artisan::call('technician:scheduled-preflight'));
        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('unverified', $result['clock']);
        $this->assertSame(0, $result['total']);
        $this->assertSame($expected, $result['enabled']);
        $this->assertSame($expected, $result['activation_authorized']);
    }

    public function test_checkbox_roundtrip_is_audited_and_preserves_emergency_stop(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Setting::setValue('technician_kill_switch', '1');
        Log::spy();
        $this->actingAs($user);
        $this->assertCheckbox(false);
        $this->post(route('settings.integrations.technician.update'), ['scheduled_approvals_enabled' => '1'])
            ->assertRedirect(route('settings.integrations'))->assertSessionHasNoErrors();
        $this->assertSame('1', Setting::getValue('scheduled_approvals_enabled'));
        $this->assertCheckbox(true);
        Log::shouldHaveReceived('info')->with('[Technician] Scheduled approvals ENABLED', ['user_id' => $user->id, 'enabled' => true])->once();
        $this->post(route('settings.integrations.technician.update'), [])
            ->assertRedirect(route('settings.integrations'))->assertSessionHasNoErrors();
        $this->assertSame('0', Setting::getValue('scheduled_approvals_enabled'));
        $this->assertCheckbox(false);
        Log::shouldHaveReceived('info')->with('[Technician] Scheduled approvals DISABLED', ['user_id' => $user->id, 'enabled' => false])->once();
        $this->assertSame('1', Setting::getValue('technician_kill_switch'));
    }

    private function assertCheckbox(bool $checked): void
    {
        $response = $this->get(route('settings.integrations'))->assertOk()
            ->assertSee('Enable scheduled (deferred) execution of approved actions');
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $fields = $xpath->query('//input[@name="scheduled_approvals_enabled"]');
        $this->assertSame(1, $fields->length);
        $this->assertSame($checked, $fields->item(0)->hasAttribute('checked'));
        $this->assertSame('checkbox', $fields->item(0)->getAttribute('type'));
    }
}
