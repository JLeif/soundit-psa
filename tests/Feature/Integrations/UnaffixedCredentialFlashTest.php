<?php

namespace Tests\Feature\Integrations;

use App\Enums\ClientStage;
use App\Enums\PersonType;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Two credentials whose names carry no credential affix, so the sweep that
 * built the rest of the dontFlash list could not see them.
 *
 * The page-wide registration (#3363) suppressed 23 names, every one of which
 * announces itself: _key, _secret, _token, _password. A pattern keyed on
 * those affixes cannot match a bare 'token' or a bare 'keys', and both are
 * secrets:
 *
 *   'token' is the portal password-reset token. It is the entire
 *   authentication for the reset request, and a failed reset -- a short new
 *   password, a mismatched confirmation -- redirects withInput() and parks
 *   the still-valid token in the session's _old_input, in the clear
 *   (SESSION_ENCRYPT=false).
 *
 *   'keys' is the PowerDMARC per-client API key map. It is an ARRAY, so one
 *   failed submit flashes every operator-entered vendor JWT on the page at
 *   once, not one credential.
 *
 * Each control asserts its own preconditions -- the request really failed
 * validation, and something really was flashed -- so it cannot pass because
 * the route 404'd behind portal.enabled or because nothing was flashed at
 * all. A refused request and a protected one look identical in _old_input.
 */
class UnaffixedCredentialFlashTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);
    }

    /**
     * A reset that fails on the password must not flash the reset token.
     *
     * The token is valid at the moment it is flashed: the submission fails
     * on 'password' (min:8), never on 'token'. Asserting the 302 and the
     * error bag is what separates a protected request from one the
     * portal.enabled middleware refused with a 404 -- that 404 also leaves
     * no token in _old_input, and would pass a bare absence check.
     */
    public function test_a_failed_portal_reset_does_not_flash_the_reset_token(): void
    {
        Setting::setValue('portal_enabled', '1');

        $client = Client::factory()->create(['stage' => ClientStage::Active]);
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Portal',
            'last_name' => 'Contact',
            'email' => 'contact@example.test',
            'is_active' => true,
            'portal_enabled' => true,
            'password' => bcrypt('old-password'),
        ]);

        $token = Password::broker('portal')->createToken($person);

        $response = $this->from(route('portal.password.request'))
            ->post(route('portal.password.update'), [
                'token' => $token,
                'email' => $person->email,
                'password' => 'short',
                'password_confirmation' => 'short',
            ]);

        // Preconditions. A 404 from portal.enabled would also flash nothing.
        $response->assertStatus(302);
        $response->assertSessionHasErrors('password');
        $this->assertNotNull(
            session('_old_input'),
            'precondition failed: nothing was flashed, so this control proves nothing'
        );

        $this->assertArrayNotHasKey(
            'token',
            session('_old_input'),
            'The portal password-reset token was flashed to the session by a failed reset.'
        );
    }

    /**
     * The positive control Jeeves named: 'email' rides on the SAME failing
     * submission and MUST still repopulate.
     *
     * Without it, a registration that simply stopped flashing everything
     * would satisfy the assertion above while breaking the form. The reset
     * view reads old('email', $email), so this is a field the page really
     * uses -- not a token invented to make the control pass.
     *
     * Deliberately not asserted on 'token': the view sources the token from
     * the route parameter, never from old input, so its absence costs
     * nothing and its presence would be the defect.
     */
    public function test_a_failed_portal_reset_still_flashes_the_email(): void
    {
        Setting::setValue('portal_enabled', '1');

        $client = Client::factory()->create(['stage' => ClientStage::Active]);
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Portal',
            'last_name' => 'Contact',
            'email' => 'contact@example.test',
            'is_active' => true,
            'portal_enabled' => true,
            'password' => bcrypt('old-password'),
        ]);

        $response = $this->from(route('portal.password.request'))
            ->post(route('portal.password.update'), [
                'token' => Password::broker('portal')->createToken($person),
                'email' => $person->email,
                'password' => 'short',
                'password_confirmation' => 'short',
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('password');

        $this->assertSame(
            $person->email,
            (session('_old_input') ?? [])['email'] ?? null,
            'The email must still repopulate the reset form after a failed attempt.'
        );
    }

    /**
     * The PowerDMARC key map is an array, so one failed submit flashes every
     * client's vendor JWT at once.
     *
     * The submission fails on 'keys.*' (max:5000) for one client while
     * carrying a valid key for another; the valid one is the credential
     * under test, so the control cannot pass merely because the failing
     * value was rejected.
     */
    public function test_a_failed_powerdmarc_save_does_not_flash_the_key_map(): void
    {
        $good = Client::factory()->create();
        $bad = Client::factory()->create();

        $validKey = str_repeat('J', 64);

        $response = $this->actingAs($this->admin())
            ->from(route('settings.powerdmarc-domains.index'))
            ->post(route('settings.powerdmarc-domains.keys.update'), [
                'keys' => [
                    (string) $good->id => $validKey,
                    (string) $bad->id => str_repeat('X', 5001),
                ],
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('keys.'.$bad->id);
        $this->assertNotNull(
            session('_old_input'),
            'precondition failed: nothing was flashed, so this control proves nothing'
        );

        $this->assertArrayNotHasKey(
            'keys',
            session('_old_input'),
            'The PowerDMARC API key map was flashed to the session by a failed save.'
        );
    }

    /**
     * The same positive control for the PowerDMARC page: an ordinary field
     * on the failing submission must still come back.
     *
     * 'clear' is a real field of that form (the per-client remove
     * checkboxes), so this measures repopulation the operator would notice.
     */
    public function test_a_failed_powerdmarc_save_still_flashes_an_ordinary_field(): void
    {
        $bad = Client::factory()->create();
        $other = Client::factory()->create();

        $response = $this->actingAs($this->admin())
            ->from(route('settings.powerdmarc-domains.index'))
            ->post(route('settings.powerdmarc-domains.keys.update'), [
                'keys' => [(string) $bad->id => str_repeat('X', 5001)],
                'clear' => [(string) $other->id => '1'],
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('keys.'.$bad->id);

        $this->assertSame(
            ['1'],
            array_values((array) ((session('_old_input') ?? [])['clear'] ?? [])),
            'An ordinary non-credential field must still repopulate the form.'
        );
    }
}
