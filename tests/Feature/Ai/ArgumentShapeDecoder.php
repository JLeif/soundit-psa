<?php

namespace App\Services\Ai;

// Test-only fault injection at the parser boundary: normal JSON decoding cannot
// produce stdClass input because AiClient explicitly requests associative arrays.
function json_decode(string $json, ?bool $associative = null, int $depth = 512, int $flags = 0): mixed
{
    $decoded = \json_decode($json, $associative, $depth, $flags);
    if (\Tests\Feature\Ai\AiClientArgumentShapeTest::$injectObject
        && is_array($decoded)
        && ($decoded['content'][0]['id'] ?? null) === 'shape_call') {
        $decoded['content'][0]['input'] = new \stdClass;
    }

    return $decoded;
}
