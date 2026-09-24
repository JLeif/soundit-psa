<?php

namespace Tests\Feature\Integrations;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A validation failure must not park a credential in the session.
 *
 * Laravel redirects a failed validate() withInput(), and the framework's
 * $dontFlash list carries exactly three entries -- current_password,
 * password and password_confirmation. Every other field in the submission,
 * including an API key or a webhook secret, is written to the session's
 * _old_input so the form can be repopulated.
 *
 * That is correct for a username and wrong for a secret. With
 * SESSION_ENCRYPT=false the session payload is serialised to the session
 * driver in the clear, so a credential the operator typed once survives
 * there for the session's lifetime -- long after the request that failed.
 *
 * These controls send a submission that FAILS validation on one field while
 * carrying a valid credential in another, and assert the credential is
 * absent from the flashed input. Each asserts its own precondition: that the
 * request really did fail validation and really did flash something, so a
 * control cannot pass because nothing was flashed at all.
 */
class CredentialFlashOnValidationFailureTest extends TestCase
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
     * The sibling Jeeves named: Level, whose update path has no protection
     * of any kind -- no replace(), no flashOnly(), no exception hook.
     *
     * The over-length webhook_secret is what fails validation (max:500);
     * the api_key beside it is valid and is the credential under test.
     */
    public function test_a_failed_level_save_does_not_flash_the_api_key(): void
    {
        $apiKey = str_repeat('K', 40);

        $response = $this->actingAs($this->admin())
            ->from(route('settings.integrations'))
            ->post(route('settings.integrations.level.update'), [
                'api_key' => $apiKey,
                'webhook_secret' => str_repeat('S', 501),
            ]);

        // Preconditions: this really is the validation-failure path, and
        // the framework really did flash the input. Without these the
        // assertion below could pass on a request that never flashed.
        $response->assertSessionHasErrors('webhook_secret');
        $this->assertNotNull(
            session('_old_input'),
            'precondition failed: nothing was flashed, so this control proves nothing'
        );

        $this->assertArrayNotHasKey(
            'api_key',
            session('_old_input'),
            'The Level API key was flashed to the session by a failed save.'
        );
    }

    /**
     * The same submission from the other direction: a valid webhook_secret
     * beside an over-length api_key. The failing field and the credential
     * swap places, so a fix that only covers one key is caught.
     */
    public function test_a_failed_level_save_does_not_flash_the_webhook_secret(): void
    {
        $secret = str_repeat('W', 40);

        $response = $this->actingAs($this->admin())
            ->from(route('settings.integrations'))
            ->post(route('settings.integrations.level.update'), [
                'api_key' => str_repeat('K', 501),
                'webhook_secret' => $secret,
            ]);

        $response->assertSessionHasErrors('api_key');
        $this->assertNotNull(
            session('_old_input'),
            'precondition failed: nothing was flashed, so this control proves nothing'
        );

        $this->assertArrayNotHasKey(
            'webhook_secret',
            session('_old_input'),
            'The Level webhook secret was flashed to the session by a failed save.'
        );
    }

    /**
     * Level's third credential field. It is neither the failing field nor
     * the one the other two controls name, so a fix listing only api_key
     * and webhook_secret leaves it behind.
     */
    public function test_a_failed_level_save_does_not_flash_the_install_account_token(): void
    {
        $token = str_repeat('T', 40);

        $response = $this->actingAs($this->admin())
            ->from(route('settings.integrations'))
            ->post(route('settings.integrations.level.update'), [
                'api_key' => str_repeat('K', 501),
                'install_account_token' => $token,
            ]);

        $response->assertSessionHasErrors('api_key');
        $this->assertNotNull(
            session('_old_input'),
            'precondition failed: nothing was flashed, so this control proves nothing'
        );

        $this->assertArrayNotHasKey(
            'install_account_token',
            session('_old_input'),
            'The Level install account token was flashed to the session by a failed save.'
        );
    }

    /**
     * A second vendor, to show the registration is page-wide rather than
     * a Level-shaped fix. Comet's admin password is a different field name
     * reached through a different controller action.
     */
    public function test_a_failed_comet_save_does_not_flash_the_admin_password(): void
    {
        $password = str_repeat('P', 30);

        $response = $this->actingAs($this->admin())
            ->from(route('settings.integrations'))
            ->post(route('settings.integrations.comet.update'), [
                'comet_admin_password' => $password,
                'comet_server_url' => 'not-a-valid-url',
            ]);

        $response->assertSessionHasErrors('comet_server_url');
        $this->assertNotNull(
            session('_old_input'),
            'precondition failed: nothing was flashed, so this control proves nothing'
        );

        $this->assertArrayNotHasKey(
            'comet_admin_password',
            session('_old_input'),
            'The Comet admin password was flashed to the session by a failed save.'
        );
    }

    /**
     * Positive control for the three above.
     *
     * A NON-credential field on the same failing submission must still be
     * flashed. Without this, a registration that simply stopped flashing
     * everything would satisfy all three credential assertions while
     * silently breaking form repopulation for every ordinary field on the
     * page -- the three controls above cannot tell those two outcomes apart.
     *
     * Deliberately NOT asserted on api_key: the whole point of the fix is
     * that a credential does not come back, so the failing credential field
     * is expected to be absent. An ordinary field rides along on the same
     * request so the two behaviours are measured on one submission.
     */
    public function test_a_failed_save_still_flashes_an_ordinary_field(): void
    {
        $response = $this->actingAs($this->admin())
            ->from(route('settings.integrations'))
            ->post(route('settings.integrations.level.update'), [
                'api_key' => str_repeat('K', 501),
                'form_section' => 'level',
            ]);

        $response->assertSessionHasErrors('api_key');

        $this->assertSame(
            'level',
            (session('_old_input') ?? [])['form_section'] ?? null,
            'An ordinary non-credential field must still repopulate the form.'
        );
    }
}
