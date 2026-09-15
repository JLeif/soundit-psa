<?php

namespace Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use PHPUnit\Framework\TestCase;

class OutboundNetworkGuardTest extends TestCase
{
    public function test_bare_guzzle_cannot_reach_the_vendor(): void
    {
        // Check configuration before attempting the control: never probe a vendor
        // if the suite guard itself was removed or overridden.
        $this->assertSame('http://127.0.0.1:9', getenv('HTTP_PROXY'));
        $this->assertSame('http://127.0.0.1:9', getenv('HTTPS_PROXY'));

        try {
            (new Client)->get('https://api.huntress.io/v1/', [
                'connect_timeout' => 1,
                'timeout' => 2,
            ]);
            $this->fail('Bare Guzzle unexpectedly reached the network.');
        } catch (ConnectException $exception) {
            $context = $exception->getHandlerContext();
            $this->assertSame(7, $context['errno'] ?? null);
            $this->assertStringContainsString('127.0.0.1', $exception->getMessage());
            $this->assertStringContainsString('port 9', $exception->getMessage());
        }
    }
}
