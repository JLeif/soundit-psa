<?php

namespace App\Services\Technician\Scheduled;

use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

/** Ciphertext is server-only; digest covers every approval field, not summaries. */
final class ApprovalEnvelope
{
    public static function seal(array $values): array
    {
        $json = self::canonical($values);

        return ['ciphertext' => Crypt::encryptString($json), 'digest' => self::digest($json)];
    }

    public static function open(string $ciphertext, string $digest): array
    {
        try {
            $json = Crypt::decryptString($ciphertext);
            if (! hash_equals(self::digest($json), $digest)) {
                throw new InvalidArgumentException('envelope_invalid');
            }
            $values = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($values) || ($values['schema_version'] ?? null) !== 1) {
                throw new InvalidArgumentException('envelope_invalid');
            }

            return $values;
        } catch (\Throwable) {
            // Do not leak decrypted input or encryption error details.
            throw new InvalidArgumentException('envelope_invalid');
        }
    }

    public static function canonical(array $values): string
    {
        $sort = function (array $value) use (&$sort): array {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $sort($item);
                }
            }

            return $value;
        };

        return json_encode($sort($values), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function digest(string $json): string
    {
        return hash_hmac('sha256', $json, (string) config('app.key'));
    }
}
