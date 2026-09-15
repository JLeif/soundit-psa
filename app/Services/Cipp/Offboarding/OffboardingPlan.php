<?php

namespace App\Services\Cipp\Offboarding;

use InvalidArgumentException;

/** Strict, pure plan validation/serialization. Identity values must come from a scope verifier. */
final class OffboardingPlan
{
    public const ACTIONS = [
        'revoke_sessions' => 'RevokeSessions',
        'disable_sign_in' => 'DisableSignIn',
        'hide_from_gal' => 'HideFromGAL',
        'convert_to_shared' => 'ConvertToShared',
        'disable_forwarding' => 'disableForwarding',
        'grant_mailbox_full_access_no_automap' => 'AccessNoAutomap',
        'grant_mailbox_full_access_automap' => 'AccessAutomap',
        'grant_mailbox_send_as' => 'AccessSendAs',
        'grant_mailbox_send_on_behalf' => 'AccessSendOnBehalf',
        'grant_onedrive_access' => 'OnedriveAccess',
        'forward_to_successor' => 'forward',
    ];

    public const RECIPIENT_ACTIONS = [
        'grant_mailbox_full_access_no_automap', 'grant_mailbox_full_access_automap',
        'grant_mailbox_send_as', 'grant_mailbox_send_on_behalf',
        'grant_onedrive_access', 'forward_to_successor',
    ];

    public static function validate(array $input): array
    {
        $required = ['client_id', 'person_id', 'ticket_id', 'confirm_upn', 'reason', 'staged', 'actions'];
        if (array_diff($required, array_keys($input)) || array_diff(array_keys($input), [...$required, 'successor_person_id', 'keep_copy'])) {
            throw new InvalidArgumentException('Missing or unsupported offboarding input.');
        }
        foreach (['client_id', 'person_id', 'ticket_id'] as $key) {
            if (! is_int($input[$key]) || $input[$key] < 1) {
                throw new InvalidArgumentException('Positive local identifiers are required.');
            }
        }
        if ($input['staged'] !== true) {
            throw new InvalidArgumentException('Offboarding is held-only; staged must be true.');
        }
        foreach (['confirm_upn' => 320, 'reason' => 1000] as $key => $limit) {
            if (! is_string($input[$key]) || trim($input[$key]) === '' || mb_strlen($input[$key]) > $limit) {
                throw new InvalidArgumentException('Invalid confirmation or reason.');
            }
        }
        if (mb_strlen($input['confirm_upn']) < 3) {
            throw new InvalidArgumentException('Invalid confirmation.');
        }
        $actions = $input['actions'];
        if (! is_array($actions) || ! array_is_list($actions) || count($actions) < 1 || count($actions) > count(self::ACTIONS)) {
            throw new InvalidArgumentException('An explicit action list is required.');
        }
        foreach ($actions as $action) {
            if (! is_string($action) || ! array_key_exists($action, self::ACTIONS)) {
                throw new InvalidArgumentException('Unsupported offboarding action.');
            }
        }
        if (count(array_unique($actions)) !== count($actions)) {
            throw new InvalidArgumentException('Duplicate actions are not allowed.');
        }
        foreach ([['grant_mailbox_full_access_no_automap', 'grant_mailbox_full_access_automap'], ['forward_to_successor', 'disable_forwarding']] as $pair) {
            if (count(array_intersect($pair, $actions)) === 2) {
                throw new InvalidArgumentException('Conflicting offboarding actions.');
            }
        }
        $recipient = count(array_intersect($actions, self::RECIPIENT_ACTIONS)) > 0;
        if ($recipient !== array_key_exists('successor_person_id', $input)) {
            throw new InvalidArgumentException('Successor is required only for recipient actions.');
        }
        if ($recipient && (! is_int($input['successor_person_id']) || $input['successor_person_id'] < 1 || $input['successor_person_id'] === $input['person_id'])) {
            throw new InvalidArgumentException('Successor must be a different local person.');
        }
        $forward = in_array('forward_to_successor', $actions, true);
        if ($forward !== array_key_exists('keep_copy', $input) || ($forward && ! is_bool($input['keep_copy']))) {
            throw new InvalidArgumentException('Forwarding requires an explicit keep_copy boolean, used nowhere else.');
        }
        sort($actions, SORT_STRING);
        $input['actions'] = $actions;
        $input['reason'] = trim($input['reason']);

        return $input;
    }

    /** Build a NEW body: no raw options, defaults, scheduling, notifications or destructive false flags. */
    public static function serialize(array $input, string $tenant, string $target, ?string $successor, string $reference): array
    {
        $input = self::validate($input);
        $recipient = count(array_intersect($input['actions'], self::RECIPIENT_ACTIONS)) > 0;
        if ($tenant === '' || ! filter_var($target, FILTER_VALIDATE_EMAIL)
            || strcasecmp($input['confirm_upn'], $target) !== 0
            || ($recipient && (! filter_var($successor, FILTER_VALIDATE_EMAIL) || strcasecmp($target, $successor) === 0))
            || (! $recipient && $successor !== null)
            || ! preg_match('/^soundpsa-offboard:[0-9a-f-]{36}:[0-9a-f-]{36}$/D', $reference)) {
            throw new InvalidArgumentException('Invalid resolved routing or correlation.');
        }
        $body = ['tenantFilter' => $tenant, 'user' => [['value' => $target]], 'reference' => $reference];
        foreach ($input['actions'] as $action) {
            $key = self::ACTIONS[$action];
            if ($action === 'forward_to_successor') {
                $body[$key] = ['value' => $successor];
                $body['KeepCopy'] = $input['keep_copy'];
            } elseif (in_array($action, self::RECIPIENT_ACTIONS, true)) {
                $body[$key] = [['value' => $successor]];
            } else {
                $body[$key] = true;
            }
        }

        return $body;
    }

    /** Recursive canonical representation for seals; list order is significant. */
    public static function canonical(array $value): string
    {
        $normalize = function (array $item) use (&$normalize): array {
            if (! array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                if (is_array($child)) {
                    $item[$key] = $normalize($child);
                }
            }

            return $item;
        };

        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function hash(array $value): string
    {
        return hash('sha256', self::canonical($value));
    }
}
