<?php

namespace App\Services\Hdb;

/**
 * The status-only outcome of one HDB portal login attempt.
 *
 * A status alone cannot tell an operator whether to re-enter the password or
 * re-enrol two-factor, so a `reason` rides along — but it is drawn from the
 * closed list below, never from the portal. The pair (status, reason) is the
 * ENTIRE observable output of the handshake: no status line, no response body,
 * no redirect target, no exception message.
 */
final readonly class HdbAuthResult
{
    /** Login succeeded, including the second factor if one was demanded. */
    public const REASON_OK = 'ok';

    /** No email or no password stored — nothing was sent anywhere. */
    public const REASON_MISSING_CREDENTIALS = 'missing_credentials';

    /**
     * The portal's own refusal notice (a `notify--bad` block) said the
     * email/password pair was invalid. A POSITIVE marker, measured 2026-09-17:
     * a re-served login form WITHOUT that notice is never this — see
     * REASON_LOGIN_NOT_EVALUATED.
     */
    public const REASON_CREDENTIALS_REJECTED = 'credentials_rejected';

    /**
     * The portal's refusal notice was its bot-guard one ("Invalid Captcha"):
     * the portal judged the hidden `g` field, not the credentials. Measured
     * 2026-09-17 by posting the HTML's ASCII `g` deliberately; this client
     * posts the JavaScript value, so seeing it live means the portal's guard
     * has changed underneath us.
     */
    public const REASON_FORM_GUARD_REFUSED = 'form_guard_refused';

    /**
     * The portal answered the credential post with its unauthenticated page and
     * NO refusal notice — the login form again, or the landing redirect. The
     * post was not evaluated as a login at all (measured 2026-09-17: an empty
     * `submit` field produced exactly this, byte-identical to the plain page
     * fetch, for every credential pair), or the portal's refusal shape has
     * changed. Either way it is a request-shape fact, not a verdict on the
     * stored credentials, and it must never read as "credentials rejected".
     */
    public const REASON_LOGIN_NOT_EVALUATED = 'login_not_evaluated';

    /**
     * The portal's refusal notice was its IP-filter one: the sign-in was
     * refused on WHERE the request came from. OBSERVED 2026-09-17 against the
     * real service subaccount, which is why this is its own symbol rather than
     * REASON_LOGIN_REFUSED_UNRECOGNISED — that symbol tells an operator to
     * suspect a portal change, and the actual fix is an account setting.
     *
     * This symbol says the portal refused at its perimeter. It does NOT prove
     * the stored pair is good, and it does NOT mean the portal said nothing
     * about that pair: a page showing this notice AND the credentials one
     * reports this symbol ({@see HdbAuthClient::REFUSAL_NOTICES} is ordered)
     * and the credentials verdict is discarded. The message below therefore
     * claims only the perimeter refusal, tells the operator a co-shown password
     * refusal is not reported, and sends them back to the password if
     * whitelisting does not fix the sign-in rather than exculpating the
     * credential.
     *
     * The notice text, the address it names and any host or contact in it stay
     * inside {@see HdbAuthClient}: this symbol and {@see message()}'s fixed
     * sentence are the entire output, same as the other two notices.
     */
    public const REASON_PORTAL_IP_FILTERED = 'portal_ip_filtered';

    /**
     * The portal showed a refusal notice, but not one of the three this client
     * recognises. Fail-closed on purpose: an unread notice is not evidence
     * about the credentials, and its text never leaves the client.
     */
    public const REASON_LOGIN_REFUSED_UNRECOGNISED = 'login_refused_unrecognised';

    /** Password accepted, a second factor was demanded, no seed is stored. */
    public const REASON_TOTP_REQUIRED_NO_SEED = 'totp_required_no_seed';

    /** A seed is stored but it is not decodable base32. */
    public const REASON_TOTP_SEED_UNUSABLE = 'totp_seed_unusable';

    /**
     * A second factor was demanded and the page did not carry a field this
     * client recognises. Distinct from a rejected code on purpose — see the
     * challenge-shape caveat on {@see HdbAuthClient}.
     */
    public const REASON_TOTP_CHALLENGE_UNRECOGNISED = 'totp_challenge_unrecognised';

    /** The generated code was submitted and refused. */
    public const REASON_TOTP_REJECTED = 'totp_rejected';

    /** DNS, TLS, connect or read timeout — nothing was learned about the credentials. */
    public const REASON_TRANSPORT_ERROR = 'transport_error';

    /** The portal answered, but not with anything this client can classify. */
    public const REASON_UNEXPECTED_RESPONSE = 'unexpected_response';

    /** The attempt hit its own request ceiling, most likely a redirect loop. */
    public const REASON_REQUEST_BUDGET_EXHAUSTED = 'request_budget_exhausted';

    /**
     * The portal tried to redirect a leg off the configured origin. Redirects
     * re-send the credential body, so the hop was refused rather than followed
     * and nothing reached that host.
     */
    public const REASON_REDIRECT_REFUSED = 'redirect_refused';

    /**
     * The stored Portal URL is not a destination this integration will post a
     * credential to — not https, or a host that is an IP literal, a bare name or
     * a reserved internal suffix. Nothing was sent anywhere.
     */
    public const REASON_PORTAL_URL_REFUSED = 'portal_url_refused';

    /**
     * The stored Portal URL passed every string-level test and then did not
     * resolve, so where it points could not be checked. Nothing was sent —
     * deliberately fail-closed — but this is an infrastructure fact, not a
     * verdict on the setting: distinct from REASON_PORTAL_URL_REFUSED so an
     * operator can tell a resolver outage from a hostile URL.
     */
    public const REASON_PORTAL_URL_UNRESOLVED = 'portal_url_unresolved';

    public function __construct(
        public HdbAuthStatus $status,
        public string $reason,
        /** HTTP requests actually issued, for the audit row and the budget tests. */
        public int $requests = 0,
    ) {}

    public function ok(): bool
    {
        return $this->status === HdbAuthStatus::Authenticated;
    }

    /**
     * The operator-facing sentence for this outcome.
     *
     * Fixed strings selected by symbol. The integrations page renders a test
     * result through `innerHTML`, so this method existing — rather than the
     * caller interpolating anything — is what keeps vendor text out of the DOM.
     */
    public function message(): string
    {
        return match ($this->reason) {
            self::REASON_OK => 'Signed in to the HDB report portal successfully.',
            self::REASON_MISSING_CREDENTIALS => 'Enter the service subaccount email and password first, then save.',
            self::REASON_CREDENTIALS_REJECTED => 'The portal refused the service subaccount email and password.',
            self::REASON_FORM_GUARD_REFUSED => 'The portal refused the sign-in at its bot check before judging the credentials. The login form has probably changed; nothing was retried.',
            self::REASON_LOGIN_NOT_EVALUATED => 'The portal answered with its signed-out page and no refusal notice, so there is no verdict on the credentials either way. Check the request shape, session handling or a portal change before the password; nothing was retried.',
            self::REASON_PORTAL_IP_FILTERED => 'The portal refused the sign-in at the HDB account\'s IP filter, which is a fact about where the request came from rather than a verdict on the stored password — and if the same page also refused the password, that refusal is discarded and not reported here. Add this PSA\'s outbound address to the IP filter whitelist on the HDB account — behind NAT or an egress proxy that is the gateway\'s address, not the server\'s own — then test again. Nothing was retried, and if the sign-in still fails afterwards re-check the password.',
            self::REASON_LOGIN_REFUSED_UNRECOGNISED => 'The portal refused the sign-in with a notice this integration does not recognise. The portal may have changed; nothing was retried.',
            self::REASON_TOTP_REQUIRED_NO_SEED => 'The password was accepted but the portal asked for a two-factor code, and no seed is stored. Paste the enrollment seed into the Two-Factor Seed field.',
            self::REASON_TOTP_SEED_UNUSABLE => 'The stored two-factor seed is not valid base32 — re-enrol two-factor and paste the seed exactly as shown.',
            self::REASON_TOTP_CHALLENGE_UNRECOGNISED => 'The password was accepted but the two-factor prompt was not in a form this integration recognises. The portal may have changed; nothing was retried.',
            self::REASON_TOTP_REJECTED => 'The generated two-factor code was refused. The stored seed is probably from a superseded enrollment.',
            self::REASON_TRANSPORT_ERROR => 'Could not reach the HDB portal. Check the Portal URL and outbound network access.',
            self::REASON_UNEXPECTED_RESPONSE => 'The HDB portal answered with something this integration could not classify. Nothing was retried.',
            self::REASON_REQUEST_BUDGET_EXHAUSTED => 'The HDB portal kept redirecting and the attempt was stopped at its request limit.',
            self::REASON_REDIRECT_REFUSED => 'The HDB portal tried to redirect the sign-in to a different host, so it was stopped and nothing was sent there. Check the Portal URL.',
            self::REASON_PORTAL_URL_REFUSED => 'The stored Portal URL is not an https:// address with a public hostname, so nothing was sent. Fix the Portal URL, or clear it to use the default portal host.',
            self::REASON_PORTAL_URL_UNRESOLVED => 'The Portal URL hostname could not be resolved, so nothing was sent. That is usually DNS or outbound network access rather than the stored URL — retry, and check the Portal URL only if it keeps failing.',
            default => 'The HDB portal login attempt did not complete.',
        };
    }
}
