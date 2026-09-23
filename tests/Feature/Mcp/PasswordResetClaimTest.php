<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Cipp\CippRestWriteClient;
use App\Services\Cipp\CippWriteHttpException;
use App\Services\Cipp\PasswordResetClaim;
use App\Services\Mcp\StaffCippWriteToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/** Single-process sqlite: second acquire while held, NOT a concurrent-process proof. */
class PasswordResetClaimTest extends TestCase
{
    use RefreshDatabase;

    private array $args;

    private int $clientId;

    private int $approver;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        Setting::setValue('cipp_enabled', '1');
        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'tenant-1');
        Setting::setValue('cipp_client_id', 'client-1');
        Setting::setEncrypted('cipp_client_secret', 'secret');
        $this->approver = User::factory()->create(['name' => 'Reset Operator'])->id;
        Setting::setValue('triage_system_user_id', (string) $this->approver);
        $client = Client::factory()->create(['cipp_tenant_domain' => 'example.onmicrosoft.com']);
        $this->clientId = $client->id;
        $person = Person::create(['client_id' => $client->id, 'person_type' => PersonType::User,
            'first_name' => 'Alex', 'last_name' => 'Example', 'email' => 'alex@example.test',
            'cipp_user_id' => 'user-123', 'cipp_upn' => 'alex@example.test', 'is_active' => true]);
        $ticket = Ticket::factory()->for($client)->create(['contact_id' => $person->id]);
        $this->args = ['client_id' => $client->id, 'person_id' => $person->id,
            'ticket_id' => $ticket->id, 'confirm_upn' => 'alex@example.test', 'reason' => 'User requested a reset.'];
    }

    private function executor(callable $upstream): StaffCippWriteToolExecutor
    {
        $vendor = Mockery::mock(CippRestWriteClient::class);
        $vendor->shouldReceive('resetUserPassword')->andReturnUsing($upstream);
        $this->app->instance(CippRestWriteClient::class, $vendor);

        return app(StaffCippWriteToolExecutor::class);
    }

    private function direct(StaffCippWriteToolExecutor $executor): array
    {
        return $executor->execute('cipp_reset_user_password', $this->args, $this->clientId, 'direct-holder');
    }

    private function stage(StaffCippWriteToolExecutor $executor): TechnicianRun
    {
        $result = $executor->execute('cipp_stage_reset_user_password', $this->args, $this->clientId, 'stage-holder');
        $this->assertArrayNotHasKey('error', $result, json_encode($result));

        return TechnicianRun::where('action_type', 'cipp_stage_reset_user_password')->latest('id')->firstOrFail();
    }

    private function success(): array
    {
        // Sanitised projection: CIPP-API c04bde0f Set-CIPPResetPassword.ps1 cloud
        // branch + Invoke-ExecResetPass.ps1 Results wrapper. No live vendor call.
        return ['success' => true, 'status' => 200, 'body' => ['Results' => [
            'resultText' => 'Successfully reset the password for Example.',
            'copyField' => 'synthetic-test-value', 'state' => 'success',
        ]]];
    }

    public function test_same_target_refuses_with_holder_start_and_clear_command_but_other_pairs_acquire(): void
    {
        $claims = app(PasswordResetClaim::class);
        $first = $claims->acquire($this->clientId, $this->args['person_id'], 'first-holder');
        $this->assertNull($first['refusal']);
        $this->travel(365)->days(); // No expiry, even long after a transport failure.
        $blocked = $claims->acquire($this->clientId, $this->args['person_id'], 'second-holder');
        $this->assertStringContainsString('first-holder, started ', $blocked['refusal']);
        $this->assertStringContainsString('php artisan cipp:clear-reset-claim '.$first['id'], $blocked['refusal']);
        $this->assertNull($claims->acquire($this->clientId + 1, $this->args['person_id'], 'other-client')['refusal']);
        $this->assertNull($claims->acquire($this->clientId, $this->args['person_id'] + 1, 'other-person')['refusal']);
    }

    public function test_direct_held_allows_staging_but_refuses_approval_and_second_direct(): void
    {
        $calls = 0;
        $executor = null;
        $executor = $this->executor(function () use (&$executor, &$calls) {
            $calls++;
            $this->assertSame(1, $calls, 'second upstream mint escaped the held claim');
            $second = $this->direct($executor);
            $this->assertStringContainsString('direct-holder, started ', $second['error'] ?? '');
            $run = $this->stage($executor);
            $approval = $executor->approveStagedRun($run, $this->approver);
            $this->assertStringContainsString('direct-holder, started ', $approval->message);

            return $this->success();
        });
        $this->assertTrue($this->direct($executor)['success']);
        $this->assertSame(1, $calls);
        $this->assertDatabaseCount('password_reset_claims', 0);
    }

    public function test_approval_held_refuses_direct(): void
    {
        $calls = 0;
        $executor = null;
        $executor = $this->executor(function () use (&$executor, &$calls) {
            $calls++;
            $this->assertSame(1, $calls, 'direct upstream escaped approval claim');
            $this->assertStringContainsString('started ', $this->direct($executor)['error'] ?? '');

            return $this->success();
        });
        $result = $executor->approveStagedRun($this->stage($executor), $this->approver);
        $this->assertSame('executed', $result->status);
        $this->assertSame(1, $calls);
        $this->assertDatabaseCount('password_reset_claims', 0);
    }

    public function test_http_failures_and_unrecognised_answers_follow_release_policy_at_both_mints(): void
    {
        foreach (['direct', 'approve'] as $path) {
            foreach ([400, 401, 429, 500, 502, 504, 'unknown', 'missing', 'warning', 'throwable'] as $answer) {
                $calls = 0;
                $executor = $this->executor(function () use ($answer, &$calls) {
                    $calls++;
                    if (is_int($answer)) {
                        throw new CippWriteHttpException($answer);
                    }
                    if ($answer === 'throwable') {
                        throw new \RuntimeException('synthetic unexpected failure');
                    }

                    return ['status' => 200, 'body' => $answer === 'missing' ? [] : ['Results' => ['state' => $answer]]];
                });
                $run = $path === 'approve' ? $this->stage($executor) : null;
                try {
                    $path === 'direct' ? $this->direct($executor) : $executor->approveStagedRun($run, $this->approver);
                } catch (\RuntimeException $e) {
                    $this->assertSame('throwable', $answer);
                }
                $this->assertSame(1, $calls, "$path/$answer must reach upstream");
                $keep = ! is_int($answer) || $answer >= 500;
                $this->assertDatabaseCount('password_reset_claims', $keep ? 1 : 0);
                if ($keep) {
                    // Avoid the still-kept 300s timer in this first commit obscuring the claim.
                    $this->travel(301)->seconds();
                    $this->assertStringContainsString('started ', $this->direct($executor)['error'] ?? '');
                    $this->assertSame(1, $calls);
                }
                DB::table('password_reset_claims')->delete();
                $this->travel(301)->seconds();
            }
        }
    }

    public function test_clear_is_audited_and_allows_a_new_reset(): void
    {
        $calls = 0;
        $executor = $this->executor(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new ConnectionException('synthetic unknown outcome');
            }

            return $this->success();
        });
        try {
            $this->direct($executor);
        } catch (ConnectionException $e) {
            $this->assertSame(1, $calls);
        }
        $held = DB::table('password_reset_claims')->first();
        $this->assertNotNull($held);
        $this->artisan('cipp:clear-reset-claim', ['id' => $held->id, '--operator' => 'Reconciler', '--reason' => 'Checked vendor log; request no longer running.'])
            ->assertFailed();
        $this->assertDatabaseCount('password_reset_claims', 1);
        $this->artisan('cipp:clear-reset-claim', ['id' => $held->id, '--operator' => 'Reconciler', '--reason' => 'Checked vendor log; request no longer running.', '--checked-cipp-log' => true])
            ->assertSuccessful();
        $audit = \App\Models\McpAuditLog::where('method', 'cipp:clear-reset-claim')->sole();
        $this->assertSame('Reconciler', $audit->actor_label);
        $this->assertSame($held->id, $audit->arguments['claim_id']);
        $this->assertSame('Checked vendor log; request no longer running.', $audit->arguments['reason']);
        $this->assertTrue($this->direct($executor)['success']);
        $this->assertSame(2, $calls);
        $this->assertDatabaseCount('password_reset_claims', 0);
    }

    public function test_definite_answer_releases_and_later_legitimate_reset_proceeds(): void
    {
        $calls = 0;
        $executor = $this->executor(function () use (&$calls) {
            $calls++;

            return $this->success();
        });
        $this->assertTrue($this->direct($executor)['success']);
        $this->assertDatabaseCount('password_reset_claims', 0);
        // Step one retains the old timer; step two removes this travel with the timer.
        $this->travel(301)->seconds();
        $this->assertTrue($this->direct($executor)['success']);
        $this->assertSame(2, $calls);
        $this->assertDatabaseCount('password_reset_claims', 0);
    }

    public function test_connection_exception_after_upstream_success_keeps_and_refuses_retry_at_both_mints(): void
    {
        foreach (['direct', 'approve'] as $path) {
            $calls = 0;
            $upstreamSucceeded = false;
            $executor = $this->executor(function () use (&$calls, &$upstreamSucceeded) {
                $calls++;
                $upstreamSucceeded = true;
                throw new ConnectionException('synthetic lost response after upstream success');
            });
            $run = $path === 'approve' ? $this->stage($executor) : null;
            try {
                $path === 'direct' ? $this->direct($executor) : $executor->approveStagedRun($run, $this->approver);
                $this->fail('expected transport exception');
            } catch (ConnectionException $e) {
                $this->assertTrue($upstreamSucceeded);
            }
            $this->assertDatabaseCount('password_reset_claims', 1);
            $this->assertStringContainsString('started ', $this->direct($executor)['error'] ?? '');
            if ($run) {
                $this->assertStringContainsString('started ', $executor->approveStagedRun($run->fresh(), $this->approver)->message);
            }
            $this->assertSame(1, $calls);
            DB::table('password_reset_claims')->delete();
        }
    }
}
