<?php

namespace Tests\Unit\Cipp;

use App\Services\Cipp\Offboarding\OffboardingPlan;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OffboardingPlanTest extends TestCase
{
    private const REF = 'soundpsa-offboard:11111111-1111-4111-8111-111111111111:22222222-2222-4222-8222-222222222222';

    private function input(string $action = 'revoke_sessions'): array
    {
        return ['client_id' => 1, 'person_id' => 2, 'ticket_id' => 3, 'confirm_upn' => 'leaver@example.test', 'reason' => 'Synthetic control', 'staged' => true, 'actions' => [$action]];
    }

    public static function actions(): array
    {
        return [
            ['revoke_sessions', 'RevokeSessions', true],
            ['disable_sign_in', 'DisableSignIn', true],
            ['hide_from_gal', 'HideFromGAL', true],
            ['convert_to_shared', 'ConvertToShared', true],
            ['disable_forwarding', 'disableForwarding', true],
            ['grant_mailbox_full_access_no_automap', 'AccessNoAutomap', [['value' => 'successor@example.test']]],
            ['grant_mailbox_full_access_automap', 'AccessAutomap', [['value' => 'successor@example.test']]],
            ['grant_mailbox_send_as', 'AccessSendAs', [['value' => 'successor@example.test']]],
            ['grant_mailbox_send_on_behalf', 'AccessSendOnBehalf', [['value' => 'successor@example.test']]],
            ['grant_onedrive_access', 'OnedriveAccess', [['value' => 'successor@example.test']]],
            ['forward_to_successor', 'forward', ['value' => 'successor@example.test']],
        ];
    }

    #[DataProvider('actions')]
    public function test_exact_complete_body(string $action, string $key, mixed $value): void
    {
        $input = $this->input($action);
        $successor = is_array($value) ? 'successor@example.test' : null;
        if ($successor !== null) {
            $input['successor_person_id'] = 4;
        }
        $expected = ['tenantFilter' => 'example.test', 'user' => [['value' => 'leaver@example.test']], 'reference' => self::REF, $key => $value];
        if ($action === 'forward_to_successor') {
            $input['keep_copy'] = false;
            $expected['KeepCopy'] = false;
        }
        $this->assertSame($expected, OffboardingPlan::serialize($input, 'example.test', 'leaver@example.test', $successor, self::REF));
    }

    public static function forbidden(): array
    {
        $cases = [];
        foreach (['DeleteUser', 'WipeMobile', 'RemoveLicenses', 'ClearImmutableId', 'ResetPass', 'Rerun', 'RerunStep', 'Action', 'TaskId', 'StepIndex', 'DeploymentId', 'tenantFilter', 'user', 'reference', 'Scheduled', 'PsaTicketId', 'PostExecution', 'options', 'OOO'] as $key) {
            foreach ([$key, strtolower($key), strtoupper($key)] as $variant) {
                foreach ([false, null, [], ''] as $value) {
                    $cases[] = [$variant, $value];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('forbidden')]
    public function test_forbidden_keys_are_rejected_even_when_false_or_empty(string $key, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        OffboardingPlan::validate([...$this->input(), $key => $value]);
    }

    public static function invalid(): array
    {
        return [
            [['staged' => false]], [['staged' => 'true']], [['client_id' => '1']],
            [['actions' => []]], [['actions' => ['revoke_sessions', 'revoke_sessions']]],
            [['actions' => ['RemoveLicenses']]], [['actions' => [false]]],
            [['actions' => ['disable_forwarding', 'forward_to_successor'], 'successor_person_id' => 4, 'keep_copy' => true]],
            [['actions' => ['grant_mailbox_full_access_automap', 'grant_mailbox_full_access_no_automap'], 'successor_person_id' => 4]],
            [['reason' => '   ']], [['reason' => null]], [['keep_copy' => false]],
            [['successor_person_id' => 4]], [['actions' => ['grant_onedrive_access']]],
            [['actions' => ['grant_onedrive_access'], 'successor_person_id' => 2]],
            [['actions' => ['forward_to_successor'], 'successor_person_id' => 4]],
            [['actions' => ['forward_to_successor'], 'successor_person_id' => 4, 'keep_copy' => 0]],
        ];
    }

    #[DataProvider('invalid')]
    public function test_invalid_semantics(array $patch): void
    {
        $this->expectException(InvalidArgumentException::class);
        OffboardingPlan::validate(array_replace($this->input(), $patch));
    }

    public function test_confirmation_cannot_retarget(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OffboardingPlan::serialize($this->input(), 'example.test', 'different@example.test', null, self::REF);
    }

    public function test_canonical_seal_covers_nested_values(): void
    {
        $this->assertSame(OffboardingPlan::hash(['b' => ['y' => 2, 'x' => 1], 'a' => 1]), OffboardingPlan::hash(['a' => 1, 'b' => ['x' => 1, 'y' => 2]]));
        $this->assertNotSame(OffboardingPlan::hash(['actions' => ['a']]), OffboardingPlan::hash(['actions' => ['b']]));
    }
}
