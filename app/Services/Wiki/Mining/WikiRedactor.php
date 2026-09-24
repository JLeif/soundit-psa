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
        // Contextual secrets need no case mix. Same policy in both consumers.
        '~(?i:https?://(?:hooks\.slack\.com/|(?:[a-z0-9-]+\.)?webhook\.office\.com/webhookb2/|(?:canary\.|ptb\.)?discord(?:app)?\.com/api(?:/v[0-9]+)?/webhooks/))[^\s<>"\x27]+'
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

    // Possessive maximal runs: no per-position rescanning of a slash path.
    private const CANDIDATE_PATTERN = '~\b[A-Za-z0-9+/_-]{25,}+~';

    /** Linear byte/segment pass; no nested regex or suffix rescans. */
    private function distinctive(string $run, bool $extension): bool
    {
        // The historical ending word boundary excludes trailing punctuation.
        $bounded = rtrim($run, '+/-');
        $plus = strrpos($bounded, '+');
        if ($plus !== false && $plus >= 24) {
            return true;
        }
        $slash = strrpos($bounded, '/');
        if ($slash === false || $slash < 24) {
            return false;
        }
        $segments = explode('/', $run);
        if ($extension) {
            array_pop($segments);
        }
        foreach ($segments as $segment) {
            if (strlen($segment) >= 8
                && strcspn($segment, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ') < strlen($segment)
                && strcspn($segment, 'abcdefghijklmnopqrstuvwxyz') < strlen($segment)
                && strcspn($segment, '0123456789') < strlen($segment)) {
                return true;
            }
        }

        return false;
    }

    private function redactDistinctive(string $text): ?string
    {
        return preg_replace_callback(self::CANDIDATE_PATTERN, function (array $match) use ($text): string {
            [$run, $offset] = $match[0];
            $end = $offset + strlen($run);
            // Bounded ASCII extension check, equivalent to .[A-Za-z][A-Za-z0-9]{0,5}\b.
            $suffix = substr($text, $end, 8).' ';
            $length = strspn($suffix, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789', 1);
            $extension = ($suffix[0] ?? '') === '.'
                && strspn($suffix, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', 1, 1) === 1
                && $length >= 1 && $length <= 6
                && ($suffix[$length + 1] ?? '') !== '_';

            return $this->distinctive($run, $extension) ? '[REDACTED:credential]' : $run;
        }, $text, -1, $count, PREG_OFFSET_CAPTURE);
    }

    /** Layer 1. Engine errors explicitly withhold the WHOLE text, never a partial result. */
    public function redact(string $text): string
    {
        foreach (self::SECRET_PATTERNS as $pattern) {
            $next = preg_replace($pattern, '[REDACTED:credential]', $text);
            if ($next === null) {
                return '[REDACTED:credential]';
            }
            $text = $next;
        }
        $next = $this->redactDistinctive($text);
        if ($next === null) {
            return '[REDACTED:credential]';
        }

        return $next;
    }

    /**
     * Layer 3 output guard. Every engine error is a credential violation, including
     * injection/marker patterns: inability to assess is never a clean result.
     *
     * @return array<int, array{class: string, pattern: string}>
     */
    public function scan(string $text): array
    {
        $violations = [];
        foreach (['credential' => self::SECRET_PATTERNS, 'injection' => self::INJECTION_PATTERNS, 'marker' => [self::MARKER_PATTERN]] as $class => $patterns) {
            foreach ($patterns as $pattern) {
                $result = preg_match($pattern, $text);
                if ($result === false) {
                    $violations[] = ['class' => 'credential', 'pattern' => $pattern];
                } elseif ($result === 1) {
                    $violations[] = ['class' => $class, 'pattern' => $pattern];
                }
            }
        }
        $result = $this->redactDistinctive($text);
        if ($result === null) {
            $violations[] = ['class' => 'credential', 'pattern' => self::CANDIDATE_PATTERN];
        } elseif ($result !== $text) {
            $violations[] = ['class' => 'credential', 'pattern' => self::CANDIDATE_PATTERN];
        }

        return $violations;
    }
}
