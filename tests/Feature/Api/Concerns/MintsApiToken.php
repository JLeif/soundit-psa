<?php

namespace Tests\Feature\Api\Concerns;

use App\Models\ApiToken;
use App\Support\ApiEndpointRegistry;

/**
 * Mints a real ApiToken through the model (hash stored, plaintext returned
 * once) and grants it endpoints, for tests that exercise the API surface.
 */
trait MintsApiToken
{
    /**
     * @param  array<int, string>|null  $endpoints  null grants every registry entry
     * @param  array<string, mixed>  $state  forceFill'd after mint (activated_at, paused_at, ...)
     * @return array{0: ApiToken, 1: string}
     */
    protected function mintApiToken(?array $endpoints = null, array $state = ['activated_at' => 'now'], string $label = 'test-token'): array
    {
        [$token, $plain] = ApiToken::mint($label);

        $fill = ['endpoints' => $endpoints ?? ApiEndpointRegistry::names()];
        foreach ($state as $k => $v) {
            $fill[$k] = $v === 'now' ? now() : $v;
        }
        $token->forceFill($fill)->save();

        return [$token->fresh(), $plain];
    }

    /** @return array<string, string> */
    protected function bearer(string $plain): array
    {
        return ['Authorization' => 'Bearer '.$plain];
    }
}
