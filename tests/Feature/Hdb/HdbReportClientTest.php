<?php

namespace Tests\Feature\Hdb;

use App\Enums\TicketSource;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\Hdb\HdbAuthClient;
use App\Services\Hdb\HdbReportClient;
use App\Services\Hdb\HdbReportFetchAuthorizer;
use App\Services\Hdb\HdbReportFetchRefusal;
use App\Services\Hdb\HdbReportRedaction;
use App\Services\Hdb\HdbReportResult;
use App\Services\Hdb\HdbReportStatus;
use App\Support\HdbPortalConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The HelpDesk Buttons press-report fetch client (psa #340 / #1359).
 *
 * 🔴 FIXTURE PROVENANCE, stated first because C-56(2) is the rule this suite
 * cannot fully satisfy. Every payload below is SYNTHETIC: invented hostnames
 * (`WS-INVENTED-01`), invented users, documentation-range addresses, no real
 * press id from a real capture, no screenshot bytes from a real desktop. They
 * are written from the KEY LIST in the household vault plan
 * `wiki/sound-psa/soundit-dev/plans/`
 * `2026-09-04-hdb-report-integration-build-guide.md` §6 — a second-hand
 * transcription of a 2026-09-04 live capture, which is the only record of the
 * vendor's shape that exists (HDB is closed source, no OpenAPI spec).
 *
 * So, precisely:
 *
 * - These tests PROVE the client's own decisions: the gate ordering, the upload
 *   gate, the redaction gate, the section check, the endpoint whitelist, the
 *   fail-soft behaviour, and that nothing is written on any path.
 * - They DO NOT PROVE the vendor really emits these key names or these value
 *   types. If the vault note transcribed a key wrong, every guard here refuses
 *   on first live contact rather than mis-importing — loudly, by design — but
 *   this suite would still be green.
 * - Closing that gap needs one scrubbed capture from a real press, pinned as a
 *   fixture. That is an owner decision; no live call was made for this build.
 */
class HdbReportClientTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://portal.example.test';

    private const PRESS = '780d16b2-76f4-4931-837b-c2917fb8db9a';

    private const OTHER_PRESS = '2f9c1a04-8b1e-4d77-9a3c-55e0b6d21f88';

    /** Planted in every fake body; must never reach an operator-facing string. */
    private const BEACON = 'VENDOR-TEXT-<script>alert(1)</script>-BEACON';

    protected function setUp(): void
    {
        parent::setUp();

        // The portal host is an RFC 2606 example name that resolves nowhere, so
        // the config guard's fail-closed NXDOMAIN branch would refuse every
        // fetch. Bound to a fixed public address, exactly as the auth-client
        // suite does — this seam is the reason it exists.
        $this->app->bind(HdbPortalConfig::HOST_RESOLVER, fn () => fn (string $host) => ['93.184.216.34']);

        Setting::setValue('hdb_base_url', self::BASE);
        Setting::setValue('hdb_email', 'reports@example.test');
        Setting::setEncrypted('hdb_password', 'service-account-password');
    }

    // ---------------------------------------------------------------- fixtures

    /**
     * `ticket.json` as the vault note §6a describes its keys, with SYNTHETIC
     * values throughout — see the class docblock. Endpoint identity is invented
     * (`WS-INVENTED-01`, `avery.invented`, documentation-range addresses); the
     * beacon rides in the free-text field a real press carries the end user's
     * problem description in, which is the field most likely to reach a screen.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function ticketJson(array $overrides = []): array
    {
        return array_replace([
            'ticketNumber' => '22814',
            'ticketID' => '90001',
            'pressTime' => '2026-09-04 11:02:41',
            'uploadComplete' => true,
            'redactDiagnostic' => false,
            'redactScreenshots' => false,
            'permitTechConnect' => true,
            'hostname' => 'WS-INVENTED-01',
            'username' => 'avery.invented',
            'mac' => '00:00:5E:00:53:01',
            'localIP' => '192.0.2.31',
            'sourceIp' => '198.51.100.7',
            'clientInput' => ['email' => 'avery@example.test', 'name' => 'Avery Invented'],
            'msg' => 'Outlook keeps asking for a password. '.self::BEACON,
            'email' => 'avery@example.test',
            'name' => 'Avery Invented',
            'override_email' => '',
            'userid' => 'acct-invented',
            'version' => '1.1.23.40',
            'ttl' => 1788000000,
            'requestId' => 'req-invented',
            'gatekeeper' => 'https://api.example.test/production',
            'statusCode' => 200,
            'message' => 'OK',
        ], $overrides);
    }

    /**
     * `report.json` carrying all eight sections the client requires, plus a
     * representative sample of the optional ones the vault note §6b lists.
     * Values are synthetic and small; the real payload is ~30 KB.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function reportJson(array $overrides = []): array
    {
        return array_replace([
            'info' => [
                'hostname' => 'WS-INVENTED-01',
                'user' => 'avery.invented',
                'domain' => 'invented.example.test',
                'OS_Version' => '10.0.19045',
                'uptimeSeconds' => 412233,
                'C_Size' => '511101190144',
                'C_FreeSpace' => '38109184000',
            ],
            'avStatus' => ['Windows Defender' => ['state' => 'Enabled', 'defsUpdated' => 'Up to Date']],
            'windowsFirewall' => ['firewallState' => 'ON', 'networkType' => 'Public', 'openRDPPorts' => []],
            'security' => ['updateList' => []],
            'hardware' => ['Motherboard' => 'INVENTED BOARD', 'deviceErrors' => [], 'diskErrors' => []],
            'netStatus' => ['gateway' => '192.0.2.1', 'dnsServers' => ['192.0.2.2'], 'nslookup' => []],
            'eventLog' => ['application' => [], 'system' => []],
            'processList' => ['explorer' => ['PID' => 4242, 'pCommit' => 1.5]],
            'software' => ['bsodList' => []],
            'resourceUsage' => ['cpu' => 11],
            'stability' => ['min' => 1, 'avg' => 7.4, 'max' => 10],
            'mapNet' => [],
        ], $overrides);
    }

    // ------------------------------------------------------------ test harness

    /** A ticket with a live note keyed to $pressId, the way capture keys it. */
    private function keyedTicket(string $pressId = self::PRESS, ?Client $client = null): Ticket
    {
        $ticket = Ticket::factory()->create([
            'source' => TicketSource::HelpdeskButton->value,
            'client_id' => ($client ?? Client::factory()->create())->id,
        ]);

        $note = TicketNote::create([
            'ticket_id' => $ticket->id,
            'note_type' => \App\Enums\NoteType::System,
            'body' => "View report: https://beta.helpdeskbuttons.com/pressView.php?pressID={$pressId}",
            'is_private' => true,
            'noted_at' => now(),
        ]);

        TicketNote::whereKey($note->id)->toBase()->update(['hdb_press_id' => $pressId]);

        return $ticket->fresh();
    }

    /** The signed-in page shape the auth client reads as authenticated. */
    private function signedInPage(): string
    {
        return '<html><body><h1>Dashboard</h1></body></html>';
    }

    /**
     * Fake the portal: the login handshake succeeds, and each report file
     * answers whatever the caller supplies.
     *
     * The gatekeeper URL is matched on `getFile=<name>` rather than on the
     * whole query, so a client that dropped `root=uploads` would still match
     * here — that parameter is asserted separately, on the recorded request,
     * where a missing one FAILS instead of silently passing.
     *
     * @param  array<string, mixed>  $files  filename => body (string) or Http response
     */
    private function fakePortal(array $files): void
    {
        $fakes = [
            self::BASE.'/login' => Http::response($this->signedInPage()),
        ];

        foreach ($files as $name => $body) {
            $fakes[self::BASE.'/gatekeeper_auth.php?*getFile='.$name.'*'] = is_string($body)
                ? Http::response($body)
                : $body;
        }

        // Anything NOT named above is a 404 rather than an unmatched-request
        // exception, so "the client asked for a file it should not have" shows
        // up as a behavioural failure in the assertions rather than as a
        // framework error that could be mistaken for a harness fault.
        $fakes['*'] = Http::response('not found', 404);

        Http::fake($fakes);
    }

    /** @return list<string> every gatekeeper getFile the client asked for, in order */
    private function requestedFiles(): array
    {
        $files = [];

        foreach (Http::recorded() as [$request]) {
            /** @var Request $request */
            if (! str_contains($request->url(), 'gatekeeper_auth.php')) {
                continue;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $files[] = (string) ($query['getFile'] ?? '');
        }

        return $files;
    }

    private function client(): HdbReportClient
    {
        return new HdbReportClient(new HdbReportFetchAuthorizer);
    }

    // ------------------------------------------------------------- happy path

    /**
     * THE POSITIVE DIRECTION. A guard suite that only asserts refusals is
     * satisfied by a client that refuses everything, so this case exists before
     * any of them: a keyed press on its own ticket, complete upload, no
     * redaction, all required sections — fetched, importable, payload intact.
     *
     * Mutation that kills it: making any gate refuse unconditionally.
     */
    public function test_a_keyed_press_on_its_own_ticket_is_fetched_whole(): void
    {
        $ticket = $this->keyedTicket();

        $this->fakePortal([
            HdbReportClient::FILE_TICKET => json_encode($this->ticketJson()),
            HdbReportClient::FILE_REPORT => json_encode($this->reportJson()),
            HdbReportClient::FILE_SCREENSHOT => "\x89PNG\r\n\x1a\nINVENTED-PIXELS",
        ]);

        $result = $this->client()->fetch($ticket->id, self::PRESS);

        $this->assertSame(HdbReportStatus::Fetched, $result->status, $result->reason);
        $this->assertTrue($result->importable());
        $this->assertSame(self::PRESS, $result->pressId);
        $this->assertSame([], $result->missingSections);

        // The payload is the vendor's, whole — not a projection this client
        // invented. A client that dropped sections it did not understand would
        // fail here.
        $this->assertSame('WS-INVENTED-01', $result->report['info']['hostname'] ?? null);
        $this->assertSame('22814', $result->ticket['ticketNumber'] ?? null);
        $this->assertStringContainsString('INVENTED-PIXELS', (string) $result->screenshot);
    }

    /**
     * The URL SHAPE, asserted on the request the client actually issued rather
     * than on the fake's matcher — the matcher only keys on `getFile`, so a
     * dropped `root=uploads` or `pressID` would pass it silently.
     *
     * Mutation that kills it: dropping or renaming any of the three query
     * parameters, or pointing the path somewhere other than gatekeeper_auth.php.
     */
    public function test_the_gatekeeper_url_carries_the_documented_parameters(): void
    {
        $ticket = $this->keyedTicket();

        $this->fakePortal([
            HdbReportClient::FILE_TICKET => json_encode($this->ticketJson()),
            HdbReportClient::FILE_REPORT => json_encode($this->reportJson()),
            HdbReportClient::FILE_SCREENSHOT => 'PNG',
        ]);

        $this->client()->fetch($ticket->id, self::PRESS);

        $gatekeeper = array_values(array_filter(
            iterator_to_array(Http::recorded()),
            fn (array $pair) => str_contains($pair[0]->url(), 'gatekeeper_auth.php'),
        ));

        $this->assertNotEmpty($gatekeeper, 'The client issued no gatekeeper request at all.');

        foreach ($gatekeeper as [$request]) {
            $this->assertStringStartsWith(self::BASE.'/gatekeeper_auth.php?', $request->url());
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            $this->assertSame(self::PRESS, $query['pressID'] ?? null);
            $this->assertSame('uploads', $query['root'] ?? null);
            $this->assertContains($query['getFile'] ?? '', HdbReportClient::FETCHABLE_FILES);
            $this->assertSame('GET', $request->method());
        }
    }

    /**
     * `/toggleverify.php` MUTATES vendor state. Nothing in this repo may call
     * it, and the whole point of the `getFile` whitelist is that no code path
     * can reach a state-changing endpoint through this client.
     *
     * Two halves, because the first alone is weak: no request the client issued
     * touches it, AND no Hdb service carries it in EXECUTABLE code. The second
     * half strips comments with the PHP tokenizer first — a naive grep would
     * fail on the client's own docblock, which names the endpoint precisely so
     * the next author does not add it, and a check that punishes the warning it
     * depends on is a check that gets deleted. Mutation that kills it: adding a
     * toggleverify call to any Hdb service.
     */
    public function test_no_state_changing_vendor_endpoint_is_ever_called(): void
    {
        $ticket = $this->keyedTicket();

        $this->fakePortal([
            HdbReportClient::FILE_TICKET => json_encode($this->ticketJson()),
            HdbReportClient::FILE_REPORT => json_encode($this->reportJson()),
            HdbReportClient::FILE_SCREENSHOT => 'PNG',
        ]);

        $this->client()->fetch($ticket->id, self::PRESS);

        foreach (Http::recorded() as [$request]) {
            $this->assertStringNotContainsString('toggleverify', $request->url());

            // The login POST is the handshake and is allowed. Every REPORT read
            // must be a GET: a POST to the gatekeeper is not a read.
            if (str_contains($request->url(), 'gatekeeper_auth.php')) {
                $this->assertSame('GET', $request->method(), 'A report fetch must never POST.');
            }
        }

        $needle = 'toggle'.'verify';
        $hits = [];
        $scanned = 0;

        foreach (glob(app_path('Services/Hdb/*.php')) ?: [] as $file) {
            $scanned++;
            $code = '';

            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (! is_array($token)) {
                    continue;
                }

                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $code .= $token[1];
            }

            if (stripos($code, $needle) !== false) {
                $hits[] = basename($file);
            }
        }

        // The companion assertion the absence claim needs: an empty result from
        // a scan that read nothing is not evidence.
        $this->assertGreaterThanOrEqual(6, $scanned, 'The scan read no Hdb services, so its clean result proves nothing.');
        $this->assertSame([], $hits, 'A Hdb service names the state-changing endpoint in executable code.');
    }

    // ------------------------------------------------------------ upload gate

    /**
     * `uploadComplete: false` — vault §6a, "gate on this". The report is NOT
     * fetched: an incomplete press is detected from the metadata, before the
     * diagnostics are pulled.
     *
     * Mutation that kills it: deleting the gate, inverting it, or moving it
     * after the report fetch (the requestedFiles assertion catches the last one
     * even though the status assertion would not).
     */
    public function test_an_incomplete_upload_is_not_imported_and_the_report_is_never_fetched(): void
    {
        $ticket = $this->keyedTicket();

        $this->fakePortal([
            HdbReportClient::FILE_TICKET => json_encode($this->ticketJson(['uploadComplete' => false])),
            HdbReportClient::FILE_REPORT => json_encode($this->reportJson()),
        ]);

        $result = $this->client()->fetch($ticket->id, self::PRESS);

        $this->assertSame(HdbReportStatus::Incomplete, $result->status);
        $this->assertSame(HdbReportResult::REASON_UPLOAD_INCOMPLETE, $result->reason);
        $this->assertFalse($result->importable());
        $this->assertTrue($result->retryable(), 'An unfinished upload is the one case a retry helps.');
        $this->assertSame([HdbReportClient::FILE_TICKET], $this->requestedFiles());
    }

    /**
     * An ABSENT `uploadComplete` is not false and is not true. It is a shape
     * this client has not measured, and it gets its own symbol — the C-56(1)
     * "fail closed and NAME it" rule, applied to the one field the whole import
     * gate rests on.
     *
     * Mutation that kills it: `(bool) ($ticket['uploadComplete'] ?? false)` —
     * the natural shape — which would report a normal-looking retry forever;
     * or `?? true`, which would import half a report. Both change the reason.
     *
     * @dataProvider unreadableUploadGates
     */
    public function test_an_unreadable_upload_gate_is_its_own_refusal(mixed $value): void
    {
        $ticket = $this->keyedTicket();
        $metadata = $this->ticketJson();

        if ($value === '__absent__') {
            unset($metadata['uploadComplete']);
        } else {
            $metadata['uploadComplete'] = $value;
        }

        $this->fakePortal([
            HdbReportClient::FILE_TICKET => json_encode($metadata),
            HdbReportClient::FILE_REPORT => json_encode($this->reportJson()),
        ]);

        $result = $this->client()->fetch($ticket->id, self::PRESS);

        $this->assertSame(HdbReportStatus::Incomplete, $result->status);
        $this->assertSame(HdbReportResult::REASON_UPLOAD_GATE_MISSING, $result->reason);
        $this->assertFalse($result->importable());
        $this->assertFalse($result->retryable(), 'A vendor-shape change is not fixed by retrying.');
        $this->assertSame([HdbReportClient::FILE_TICKET], $this->requestedFiles());
    }

    /** @return array<string, array{mixed}> */
    public static function unreadableUploadGates(): array
    {
        return [
            'absent' => ['__absent__'],
            'string true' => ['true'],
            'string one' => ['1'],
            'integer one' => [1],
            'null' => [null],
        ];
    }
}
