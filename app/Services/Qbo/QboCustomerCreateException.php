<?php

namespace App\Services\Qbo;

use RuntimeException;

/**
 * Why a "create a QuickBooks customer for this client" request did not end
 * with the client linked (#3737).
 *
 * The kinds split on the one fact the operator must act on: whether a
 * customer may now exist in QuickBooks. A vendor create cannot be rolled back
 * by SQL, so an arm that reached QBO's POST must never read like an arm that
 * did not.
 *
 *  - ALREADY_MAPPED, NAME_EXISTS, PREFLIGHT_FAILED, INVALID_NAME: no POST was
 *    sent.
 *  - REJECTED: the POST was answered with a 4xx; QuickBooks' own message is
 *    carried in the text.
 *  - OUTCOME_UNKNOWN: the create got no usable answer (no response, a 5xx,
 *    an unreadable body, or a failure before the request could be sent that
 *    QboClient reports without a status); PSA cannot tell whether a customer
 *    exists, so it says exactly that.
 *  - CREATED_NOT_LINKED: QuickBooks returned a customer, and PSA failed to
 *    store the link. $qboCustomerId names it.
 */
class QboCustomerCreateException extends RuntimeException
{
    public const ALREADY_MAPPED = 'already_mapped';

    public const INVALID_NAME = 'invalid_name';

    public const NAME_EXISTS = 'name_exists';

    public const PREFLIGHT_FAILED = 'preflight_failed';

    public const REJECTED = 'rejected';

    public const OUTCOME_UNKNOWN = 'outcome_unknown';

    public const CREATED_NOT_LINKED = 'created_not_linked';

    /**
     * @param  list<array{Id: string, DisplayName: string, Active: bool, linked_client: ?string}>  $matches
     */
    private function __construct(
        string $message,
        public readonly string $kind,
        public readonly ?string $qboCustomerId = null,
        public readonly array $matches = [],
    ) {
        parent::__construct($message);
    }

    public static function alreadyMapped(string $qboCustomerId): self
    {
        return new self(
            "This client is already linked to QuickBooks customer Id {$qboCustomerId}. Nothing was sent to QuickBooks.",
            self::ALREADY_MAPPED,
            $qboCustomerId,
        );
    }

    public static function invalidName(string $reason): self
    {
        return new self(
            "Cannot create a QuickBooks customer: {$reason} Nothing was sent to QuickBooks.",
            self::INVALID_NAME,
        );
    }

    /**
     * @param  list<array{Id: string, DisplayName: string, Active: bool, linked_client: ?string}>  $matches
     */
    public static function nameExists(string $name, array $matches): self
    {
        $described = array_map(function (array $m): string {
            $text = "\"{$m['DisplayName']}\" (Id {$m['Id']}".($m['Active'] ? '' : ', inactive').')';

            return $m['linked_client'] !== null
                ? $text.' already linked to PSA client "'.$m['linked_client'].'"'
                : $text;
        }, $matches);

        return new self(
            "QuickBooks already has a customer named like \"{$name}\": ".implode('; ', $described)
            .'. No customer was created. Use Link to connect this client to the existing customer instead.',
            self::NAME_EXISTS,
            null,
            $matches,
        );
    }

    public static function preflightFailed(string $name, string $detail): self
    {
        return new self(
            "Could not check QuickBooks for an existing customer named \"{$name}\", so no customer was created. {$detail}",
            self::PREFLIGHT_FAILED,
        );
    }

    public static function rejected(string $qboMessage): self
    {
        return new self(
            "QuickBooks rejected the new customer: {$qboMessage}. The client was not linked.",
            self::REJECTED,
        );
    }

    public static function outcomeUnknown(string $name, string $detail): self
    {
        return new self(
            "The create did not get a usable answer from QuickBooks ({$detail}). PSA cannot tell whether a customer named \"{$name}\" was created: search QuickBooks for it and use Link if it is there, before pressing Create again. The client was not linked.",
            self::OUTCOME_UNKNOWN,
        );
    }

    public static function createdNotLinked(string $qboCustomerId, string $displayName, string $detail): self
    {
        return new self(
            "QuickBooks customer \"{$displayName}\" (Id {$qboCustomerId}) now EXISTS in QuickBooks, but PSA could not save the link to this client ({$detail}). Do not press Create again: use Link to connect this client to Id {$qboCustomerId}, or remove that customer in QuickBooks.",
            self::CREATED_NOT_LINKED,
            $qboCustomerId,
        );
    }

    /** True on every arm where a QuickBooks customer exists or may exist because of this request. */
    public function mayExistInQbo(): bool
    {
        return in_array($this->kind, [self::OUTCOME_UNKNOWN, self::CREATED_NOT_LINKED], true);
    }
}
