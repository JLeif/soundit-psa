<?php

namespace App\Services\Technician\Scheduled;

/** Bounded producer strings, NOT HTTP success. CIPP-API c04bde0f, Set-CIPP{Forwarding,
 * MailboxType,HideFromGAL,OutOfOffice,MailboxPermission}. Related client-wide follow-up #499.
 * Unknown/mixed effects and transport errors are terminal UNCERTAIN, never retryable.
 */
final class MailboxResult
{
    public static function classify(array $plan, array $response): string
    {
        $results = $response['body']['Results'] ?? null;
        $rows = is_string($results) ? [$results] : $results;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) < 1 || count($rows) > 2) {
            return 'uncertain';
        }
        foreach ($rows as $row) {
            if (! is_string($row) || strlen($row) > 8192) {
                return 'uncertain';
            }
        }
        $p = $plan['params'];
        $u = $plan['people']['owner']['upn'];
        $success = null;
        $failure = null;
        switch ($plan['action']) {
            case 'cipp_stage_set_mailbox_delegate':
                $who = $plan['people']['delegate_person']['upn'];
                $permission = match ($p['permission']) {
                    'full_access' => 'FullAccess', 'send_as' => 'SendAs', 'send_on_behalf' => 'SendOnBehalf'
                };
                $verb = $p['operation'] === 'grant' ? 'Granted' : 'Removed';
                $direction = $p['operation'] === 'grant' ? 'to' : 'from';
                $success = "$verb $who $permission".($permission === 'FullAccess' ? '' : ' permissions')." $direction $u";
                if ($permission === 'FullAccess' && $p['operation'] === 'grant') {
                    $success .= ' with automapping '.($p['auto_map'] ? 'True' : 'False');
                }
                $action = $p['operation'] === 'grant' ? 'Add' : 'Remove';
                $failure = "Failed to $action $permission for $who on $u: ";
                break;
            case 'cipp_stage_convert_mailbox':
                $success = "Successfully converted $u to a {$p['mailbox_type']} mailbox";
                $failure = "Failed to convert $u to a {$p['mailbox_type']} mailbox. Error: ";
                break;
            case 'cipp_stage_set_mailbox_gal_visibility':
                $text = $p['hidden'] ? 'hidden' : 'unhidden';
                $success = "Successfully $text $u from GAL.";
                $failure = "Failed to set $u to $text in GAL. Error: ";
                break;
            case 'cipp_stage_set_mailbox_forwarding':
                $failure = "Failed to set forwarding for $u. Error: ";
                if ($p['mode'] === 'disabled') {
                    if (count($rows) === 2 && $rows[0] === "Disabling forwarding for $u") {
                        array_shift($rows);
                    }
                    $success = "Successfully disabled forwarding for $u";
                } else {
                    $kind = $p['mode'] === 'internal' ? 'Internal' : 'External';
                    $target = $p['mode'] === 'internal' ? $plan['people']['target_person']['upn'] : $p['external_smtp'];
                    $success = "Successfully set forwarding for $u to $kind Address $target with keeping a copy set to ".($p['keep_copy'] ? 'True' : 'False');
                }
                break;
            case 'cipp_stage_set_mailbox_out_of_office':
                $failure = "Could not set Out of Office for user: $u. Error: ";
                $success = "Set Out-of-office for $u to {$p['state']}.";
                // Scheduled emits locale-formatted times: not an exact receipt; remain uncertain.
                if ($p['state'] === 'Scheduled') {
                    $success = null;
                }
                break;
        }
        if (count($rows) !== 1) {
            return 'uncertain';
        }
        if ($failure !== null && str_starts_with($rows[0], $failure) && strlen($rows[0]) > strlen($failure)) {
            return 'failed';
        }
        if (($response['status'] ?? null) === 200 && $success !== null && $rows[0] === $success) {
            return 'completed';
        }

        return 'uncertain';
    }
}
