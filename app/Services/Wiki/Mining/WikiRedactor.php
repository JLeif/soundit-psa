<?php

namespace App\Services\Wiki\Mining;

class WikiRedactor
{
    /**
     * Secret-shape corpus (spec §5.2 layer 1). Order matters: PEM and connection
     * strings before generic keyword forms. Known gaps (documented in spec §5.2):
     * dictated character-by-character secrets, base32 TOTP seeds.
     */
    private const SECRET_PATTERNS = [
        // PEM blocks (multi-line)
        '/-----BEGIN [A-Z ]+-----.*?-----END [A-Z ]+-----/s',
        // connection strings with embedded credentials
        '/\b[a-z][a-z0-9+.-]*:\/\/[^\s:@\/]+:[^\s@\/]+@[^\s]+/i',
        // keyword = / : value forms (password, pass, pwd, pw, secret, token, api key, license).
        // NOTE: the bare `token` keyword is a known false-positive class (also matches "token is
        // expired", "token was rotated", etc.). Accepted: this is PRE-AI input redaction, so the
        // AI still sees the surrounding context (only the value is gone, the sentence shape
        // remains); and the alternative — requiring an auth_/access_ prefix — would under-catch
        // real bare "token: <value>" forms that ticket authors commonly write. Do not narrow it.
        '/\b(?:password|passwd|pass|pwd|pw|secret|api[_\s-]?key|access[_\s-]?key|auth[_\s-]?token|token|license[_\s-]?key)\s*(?:is|was|[:=])\s*\S+/i',
        // conversational: "set the X password to VALUE", "credentials are user / pass"
        '/\b(?:password|passphrase|pin)\s+(?:to|is now|set to)\s+\S+/i',
        '/\bcredentials?\s+(?:are|is)\s+\S+(?:\s*\/\s*\S+)?/i',
        // JWT-shaped tokens (three base64url segments)
        '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{5,}(?:\.[A-Za-z0-9_-]+)?/',
        // Shared distinctive-run policy: '+' retains its old behavior; '/' alone
        // additionally requires upper/lower/digit mix in a slash-free segment
        // of at least 16 characters. Separate path segments cannot supply the mix. Ordinary
        // lowercase URL paths are not evidence of base64. C1 bare identifiers stay
        // clean. This is a heuristic, not entropy proof (see scanner coverage report).
        // Webhook paths and signed query values need contextual detection: their
        // credentials can be lowercase/hex, so case mix cannot protect those shapes.
        // No URL or channel exemption: these alternatives apply in redact AND scan.
        '~\b[A-Za-z0-9+/_-]{24,}\+[A-Za-z0-9+/_-]*={0,2}\b'
        .'|\b(?=(?:[A-Za-z0-9+_-]*/)*(?=[A-Za-z0-9+_-]*[A-Z])(?=[A-Za-z0-9+_-]*[a-z])(?=[A-Za-z0-9+_-]*[0-9])[A-Za-z0-9+_-]{16,})[A-Za-z0-9+/_-]{24,}/[A-Za-z0-9+/_-]*\b'
        .'|(?i:https?://(?:hooks\.slack\.com/services/|(?:[a-z0-9-]+\.)?webhook\.office\.com/webhookb2/|(?:canary\.|ptb\.)?discord(?:app)?\.com/api(?:/v[0-9]+)?/webhooks/))[^\s<>"\x27]+'
        .'|[?&](?i:sig|signature|x-amz-signature|x-goog-signature)=[^\s&#<>"\x27]+'
        .'~',
        // Padding boundary: a trailing \b after '=' (a non-word char) only matches when a WORD
        // char follows the padding, so padded tokens at end-of-string or before whitespace (the
        // common case) escaped. Use a non-word lookahead so EOL/whitespace match too.
        '/\b[A-Za-z0-9+\/_-]{32,}={1,2}(?!\w)/',
    ];

    private const INJECTION_PATTERNS = [
        '/\bignore\s+(?:all\s+)?(?:previous|prior|above)\s+instructions\b/i',
        '/\bdisregard\s+(?:all\s+)?(?:previous|prior)\s+instructions\b/i',
        '/^\s*(?:system|assistant)\s*:/im',
        '/\[\s*\/?INST\s*\]/i',
        '/<\s*\/?(?:system|instructions?)\s*>/i',
        '/\byou\s+must\s+always\b/i',
        '/\bnew\s+(?:system\s+)?prompt\b/i',
    ];

    // Composed pages delimit fact blocks with these; a statement containing one
    // would corrupt splicing (spec carry-over: marker-string guard).
    private const MARKER_PATTERN = '/<!--\s*wiki:facts:[a-z0-9-]*:(?:start|end)\s*-->/i';

    /** Layer 1: rewrite untrusted input before the AI sees it. */
    public function redact(string $text): string
    {
        foreach (self::SECRET_PATTERNS as $pattern) {
            $text = preg_replace($pattern, '[REDACTED:credential]', $text);
        }

        return $text;
    }

    /**
     * Layer 3 + injection + marker guard: scan AI OUTPUT before storage.
     * Any 'injection' or 'marker' violation quarantines the run; a 'credential'
     * violation drops only the offending candidate (see MineTicketKnowledge stage 3).
     *
     * @return array<int, array{class: string, pattern: string}>
     */
    public function scan(string $text): array
    {
        $violations = [];

        foreach (self::SECRET_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                $violations[] = ['class' => 'credential', 'pattern' => $pattern];
            }
        }
        foreach (self::INJECTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                $violations[] = ['class' => 'injection', 'pattern' => $pattern];
            }
        }
        if (preg_match(self::MARKER_PATTERN, $text)) {
            $violations[] = ['class' => 'marker', 'pattern' => self::MARKER_PATTERN];
        }

        return $violations;
    }
}
