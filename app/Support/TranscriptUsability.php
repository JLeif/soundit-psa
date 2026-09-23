<?php

namespace App\Support;

/** Attention heuristic, not an assertion that the recording contains silence. */
class TranscriptUsability
{
    public const WARNING = 'Transcript unusable — listen to the recording.';

    /**
     * Whole-transcript phrases reported in the incident, not substring bans.
     * You / Thank you / Bye. Bye. / Ok. Thank you. Bye-bye. and the solicitation:
     * incident observations 2026-09-22. Watching/subtitle variants: corpus probe
     * the same day. These may be real speech; the outcome asks for human review.
     */
    private const STOCK = [
        'you',
        'thank you',
        'bye bye',
        'ok thank you bye bye',
        '请不吝点赞 订阅 转发 打赏支持明镜与点点栏目',
        'thank you for watching see you next time',
        'subs by www zeoranger co uk',
    ];

    public function isUnusable(string $transcript, ?int $duration): bool
    {
        $text = trim($transcript);
        $normalized = mb_strtolower($text, 'UTF-8');
        $normalized = trim(preg_replace('/[\p{P}\p{Z}\s]+/u', ' ', $normalized) ?? $normalized);

        if ($text === '' || in_array($normalized, self::STOCK, true)) {
            return true;
        }

        // Unicode characters, not bytes: byte counts inflate CJK density.
        // <=5s and missing durations cannot support this ratio; stock-only there.
        // Strictly below 2 keeps the measured greeting-rich 2–6 band untouched.
        return $duration !== null && $duration > 5
            && mb_strlen($text, 'UTF-8') / $duration < 2.0;
    }
}
