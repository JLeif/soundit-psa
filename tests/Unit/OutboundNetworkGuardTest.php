<?php

namespace Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Utils;
use PHPUnit\Framework\TestCase;

class OutboundNetworkGuardTest extends TestCase
{
    /**
     * The suite's egress containment, asserted at the reader that decides where the packet goes.
     *
     * WHY NOT getenv(): PHPUnit's force="true" writes $_ENV and putenv() but NOT $_SERVER, and
     * GuzzleHttp\Utils::getenv() (vendor Utils.php:918-929) reads $_SERVER FIRST. So an ambient
     * HTTPS_PROXY in the runner's environment overrides the phpunit.xml pin at the only reader
     * that routes the request, while getenv() still returns the pinned value. A guard reading
     * getenv() therefore reports PASS while live traffic leaves the box. Measured on VM 102.
     *
     * NO_PROXY is pinned and asserted for the same reason: Client.php:363-367 builds
     * $defaults['proxy']['no'] from it, so an ambient NO_PROXY=* makes Guzzle skip the proxy
     * for every host while both pinned proxy values stay byte-perfect and every other reading
     * still looks correct.
     */
    public function test_the_egress_pin_holds_at_the_reader_guzzle_dials_from(): void
    {
        $this->assertSame(
            'http://127.0.0.1:9',
            Utils::getenv('HTTP_PROXY'),
            'HTTP_PROXY does not resolve to the sink THROUGH GuzzleHttp\Utils::getenv(). '
            .'$_SERVER takes precedence over the phpunit.xml pin at this reader, so the suite '
            .'can reach the real network even though getenv() still reports the pinned value.'
        );

        $this->assertSame(
            'http://127.0.0.1:9',
            Utils::getenv('HTTPS_PROXY'),
            'HTTPS_PROXY does not resolve to the sink THROUGH GuzzleHttp\Utils::getenv(). '
            .'This is the reader that routes an outbound TLS request; the pin being intact in '
            .'getenv() or phpunit.xml says nothing about it.'
        );

        $this->assertSame(
            '',
            (string) Utils::getenv('NO_PROXY'),
            'NO_PROXY is set. Guzzle builds its proxy bypass list from this value, so a non-empty '
            .'NO_PROXY (notably "*") makes every request skip the proxy entirely while both pinned '
            .'proxy values remain correct and unaltered.'
        );
    }

    /**
     * The value that actually decides routing, downstream of every environment-level bypass.
     *
     * Asserting the built client's effective `proxy` config catches a bypass regardless of which
     * variable or reader produced it, so this control does not depend on enumerating the ways
     * the environment can be subverted.
     */
    public function test_a_built_client_carries_the_sink_as_its_effective_proxy(): void
    {
        $config = (new Client)->getConfig();

        $this->assertArrayHasKey(
            'proxy',
            $config,
            'A default Guzzle client was built with NO proxy config at all: the suite has no '
            .'egress containment and any test can reach the real network.'
        );

        $this->assertSame(
            'http://127.0.0.1:9',
            $config['proxy']['https'] ?? null,
            'The built client would send HTTPS somewhere other than the sink.'
        );

        $this->assertSame(
            [],
            $config['proxy']['no'] ?? [],
            'The built client carries a proxy bypass list, so some or all hosts are dialled '
            .'directly regardless of the pinned proxy values.'
        );
    }

    public function test_bare_guzzle_cannot_reach_the_vendor(): void
    {
        // Check configuration before attempting the control: never probe a vendor
        // if the suite guard itself was removed or overridden. These read through
        // Utils::getenv() for the reason documented on the first test in this file.
        $this->assertSame('http://127.0.0.1:9', Utils::getenv('HTTP_PROXY'));
        $this->assertSame('http://127.0.0.1:9', Utils::getenv('HTTPS_PROXY'));
        $this->assertSame('', (string) Utils::getenv('NO_PROXY'));

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
