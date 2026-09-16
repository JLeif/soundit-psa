<?php

namespace App\Services\Technician\Scheduled;

use InvalidArgumentException;

/** CIPP-API c04bde0f: Invoke-Exec{EmailForward,SetOoO,EditMailboxPermissions,HideFromGAL,ConvertMailbox}. */
final class MailboxPlan
{
    public const ACTIONS = [
        'cipp_stage_set_mailbox_forwarding', 'cipp_stage_set_mailbox_out_of_office',
        'cipp_stage_set_mailbox_delegate', 'cipp_stage_set_mailbox_gal_visibility', 'cipp_stage_convert_mailbox',
    ];

    public static function supports(string $action): bool
    {
        return in_array($action, self::ACTIONS, true);
    }

    /** Serialize only already-validated, sealed parameters. No generic endpoint passthrough. */
    public static function wire(array $plan): array
    {
        $p = $plan['params'];
        $upn = $plan['people']['owner']['upn'];
        $tenant = $plan['tenant'];
        switch ($plan['action']) {
            case 'cipp_stage_convert_mailbox':
                return ['api/ExecConvertMailbox', ['tenantFilter' => $tenant, 'ID' => $upn, 'MailboxType' => $p['mailbox_type']]];
            case 'cipp_stage_set_mailbox_gal_visibility':
                return ['api/ExecHideFromGAL', ['tenantFilter' => $tenant, 'ID' => $upn, 'HideFromGAL' => $p['hidden']]];
            case 'cipp_stage_set_mailbox_forwarding':
                return ['api/ExecEmailForward', ['tenantFilter' => $tenant, 'userID' => $upn,
                    'ForwardInternal' => $p['mode'] === 'internal' ? $plan['people']['target_person']['upn'] : null,
                    'ForwardExternal' => $p['mode'] === 'external' ? $p['external_smtp'] : null,
                    'forwardOption' => match ($p['mode']) {
                        'internal' => 'internalAddress', 'external' => 'ExternalAddress', 'disabled' => 'disabled', default => throw new InvalidArgumentException('invalid_mode')
                    },
                    'KeepCopy' => $p['mode'] !== 'disabled' && $p['keep_copy'] ? 'true' : 'false']];
            case 'cipp_stage_set_mailbox_out_of_office':
                $body = ['tenantFilter' => $tenant, 'userId' => $upn, 'AutoReplyState' => $p['state']];
                foreach (['internal_message' => 'InternalMessage', 'external_message' => 'ExternalMessage', 'timezone' => 'timezone'] as $key => $field) {
                    if (isset($p[$key])) {
                        $body[$field] = $p[$key];
                    }
                }
                if ($p['state'] === 'Scheduled') {
                    $body['StartTime'] = $p['start_time'];
                    $body['EndTime'] = $p['end_time'];
                }

                return ['api/ExecSetOoO', $body];
            case 'cipp_stage_set_mailbox_delegate':
                $body = ['TenantFilter' => $tenant, 'UserID' => $upn,
                    'AddFullAccess' => [], 'AddFullAccessNoAutoMap' => [], 'RemoveFullAccess' => [],
                    'AddSendAs' => [], 'RemoveSendAs' => [], 'AddSendOnBehalf' => [], 'RemoveSendOnBehalf' => []];
                $bucket = match ($p['permission'].':'.$p['operation']) {
                    'full_access:grant' => $p['auto_map'] ? 'AddFullAccess' : 'AddFullAccessNoAutoMap',
                    'full_access:remove' => 'RemoveFullAccess', 'send_as:grant' => 'AddSendAs', 'send_as:remove' => 'RemoveSendAs',
                    'send_on_behalf:grant' => 'AddSendOnBehalf', 'send_on_behalf:remove' => 'RemoveSendOnBehalf',
                    default => throw new InvalidArgumentException('invalid_permission'),
                };
                $trustee = $plan['people']['delegate_person']['upn'];
                $body[$bucket] = [['value' => $trustee, 'label' => $trustee]];

                return ['api/ExecEditMailboxPermissions', $body];
            default:
                throw new InvalidArgumentException('unsupported_action');
        }
    }
}
