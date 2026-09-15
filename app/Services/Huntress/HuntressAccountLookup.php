<?php

namespace App\Services\Huntress;

use App\Support\HuntressConfig;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Bounded, read-only settings lookup; never follows a credential-bearing redirect. */
class HuntressAccountLookup
{
    public function id(): string
    {
        if (! HuntressConfig::isConfigured()) {
            throw new RuntimeException('Huntress API credentials are not configured.');
        }

        // Official schema: https://api.huntress.io/v1/swagger_doc.json,
        // GET /v1/account -> account.id (the credentials' top-level account).
        $response = Http::withBasicAuth(HuntressConfig::get('api_key'), HuntressConfig::get('api_secret'))
            ->acceptJson()->connectTimeout(5)->timeout(10)->withoutRedirecting()
            ->get('https://api.huntress.io/v1/account');
        $id = $response->successful() ? $response->json('account.id') : null;
        if ((! is_int($id) && ! is_string($id))
            || ! preg_match('/^[1-9][0-9]*$/D', (string) $id)
            || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new RuntimeException('Unable to verify the Huntress account ID.');
        }

        return (string) $id;
    }
}
