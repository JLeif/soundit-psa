<?php

namespace App\Services\Hdb;

/**
 * A redirect hop left the configured portal origin.
 *
 * This is the guard that keeps the decrypted service subaccount password on the
 * configured origin. The redirect policy follows BROWSER semantics
 * (`strict => false`), so a 302/303 drops the credential body — but a 307/308
 * carries it verbatim regardless, the followed request still carries the session
 * cookie, and this portal's own sign-in chain mixes both. So an off-origin hop
 * is refused before it is followed rather than reasoned about. Refusing one
 * means throwing out of the `on_redirect` callback; this type exists so
 * {@see HdbAuthClient} can tell that refusal apart from an ordinary transport
 * failure and report it as its own reason.
 *
 * It deliberately carries no message: the URL it refused is exactly the text
 * that must not escape this package.
 */
final class HdbRedirectRefusedException extends \RuntimeException {}
