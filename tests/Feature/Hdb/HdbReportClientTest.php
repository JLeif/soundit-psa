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

    // --------------------------------------------------------- redaction gate

    /**
     * `redactScreenshots: true` — the image is NEVER REQUESTED. Not fetched and
     * discarded: a screenshot that reached this process would already be the
     * thing the end user asked to withhold, sitting in memory and in whatever
     * logged the response.
     *
     * Mutation that kills it: fetching the screenshot unconditionally and
     * nulling it afterwards — which the status assertion alone would NOT catch,
     * which is why the requested-files list is asserted.
     */
    public function test_a_redacted_screenshot_is_never_requested_from_the_portal(): void
    {
        $ticket = $this->keyedTicket();

        $this->fakePortal([
            HdbReportClient::FILE_TICKET => json_encode($this->ticketJson(['redactScreenshots' => true])),
            HdbReportClient::FILE_REPORT => json_encode($this->reportJson()),
            HdbReportClient::FILE_SCREENSHOT => 'PNG-THAT-MUST-NOT-BE-FETCHED',
        ]);

        $result = $this->client()->fetch($ticket->id, self::PRESS);

        $this->assertSame(HdbReportStatus::Fetched, $result->status, $result->reason);
        $this->assertNull($result->screenshot);
        $this->assertSame(
            [HdbReportClient::FILE_TICKET, HdbReportClient::FILE_REPORT],
            $this->requestedFiles(),
            'A press whose screenshots are redacted must never have screen.png requested.',
        );
        $this->assertTrue($result->redaction?->screenshots);
    }

    /**
     * The POSITIVE direction of the same gate: with redaction off the
     * screenshot IS requested. Without this, a client that never fetched a
     * screenshot at all would pass the refusal case above.
     */
    public function test_an_unredacted_screenshot_is_requested(): void
    {
        $ticket = $this->keyedTicket();

        $this->fakePortal([
            HdbReportClient::FILE_TICKET => json_encode($this->ticketJson(['redactScreenshots' => false])),
            HdbReportClient::FILE_REPORT => json_encode($this->reportJson()),
            HdbReportClient::FILE_SCREENSHOT => 'PNG-INVENTED',
        ]);

        $result = $this->client()->fetch($ticket->id, self::PRESS);

        $this->assertContains(HdbReportClient::FILE_SCREENSHOT, $this->requestedFiles());
        $this->assertSame('PNG-INVENTED', $result->screenshot);
        $this->assertFalse($result->redaction?->screenshots);
    }

    /**
     * `redactDiagnostic` is carried to the caller rather than silently dropped.
     * What a redacted import WRITES is the import path's decision and is not in
     * this slice; what this slice must not do is lose the flag on the way.
     *
     * Mutation that kills it: reading only redactScreenshots and hard-coding
     * diagnostic false.
     */
    public function test_the_diagnostic_redaction_flag_reaches_the_caller(): void
    {
        $ticket = $this->keyedTicket();

        $this->fakePortal([
            HdbReportClient::FILE_TICKET => json_encode($this->ticketJson(['redactDiagnostic' => true])),
            HdbReportClient::FILE_REPORT => json_encode($this->reportJson()),
            HdbReportClient::FILE_SCREENSHOT => 'PNG',
        ]);

        $result = $this->client()->fetch($ticket->id, self::PRESS);

        $this->assertTrue($result->redaction?->diagnostic, 'The diagnostic redaction flag was lost.');
    }

    /**
     * AN UNREADABLE REDACTION FLAG IS A REFUSAL, never "not redacted".
     *
     * This is the single most dangerous default on this surface: the natural
     * shape, `(bool) ($ticket['redactScreenshots'] ?? false)`, publishes a
     * desktop screenshot the end user asked to withhold, the first time the
     * vendor renames a key, and does it silently. Mutation that kills it:
     * exactly that coalesce.
     *
     * @dataProvider unreadableRedactionFlags
     *
     * @param  array<string, mixed>  $mutation
     */
    public function test_an_unreadable_redaction_flag_refuses_rather_than_assuming_no_redaction(array $mutation): void
    {
        $ticket = $this->keyedTicket();
        $metadata = $this->ticketJson();

        foreach ($mutation as $key => $value) {
            if ($value === '__absent__') {
                unset($metadata[$key]);
            } else {
                $metadata[$key] = $value;
            }
        }

        $this->fakePortal([
            HdbReportClient::FILE_TICKET => json_encode($metadata),
            HdbReportClient::FILE_REPORT => json_encode($this->reportJson()),
            HdbReportClient::FILE_SCREENSHOT => 'PNG-THAT-MUST-NOT-BE-FETCHED',
        ]);

        $result = $this->client()->fetch($ticket->id, self::PRESS);

        $this->assertSame(HdbReportResult::REASON_REDACTION_FLAG_UNREADABLE, $result->reason);
        $this->assertFalse($result->importable());
        $this->assertSame(
            [HdbReportClient::FILE_TICKET],
            $this->requestedFiles(),
            'Neither the report nor the screenshot may be fetched when redaction cannot be read.',
        );
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function unreadableRedactionFlags(): array
    {
        return [
            'screenshots absent' => [['redactScreenshots' => '__absent__']],
            'diagnostic absent' => [['redactDiagnostic' => '__absent__']],
            'screenshots as string' => [['redactScreenshots' => 'false']],
            'screenshots as int' => [['redactScreenshots' => 0]],
            'both null' => [['redactScreenshots' => null, 'redactDiagnostic' => null]],
        ];
    }

    /**
     * The redaction reader in isolation, both directions, so the refusal above
     * cannot be satisfied by a reader that returns null for everything.
     */
    public function test_the_redaction_reader_accepts_booleans_and_refuses_everything_else(): void
    {
        $ok = HdbReportRedaction::fromTicket(['redactDiagnostic' => true, 'redactScreenshots' => false]);

        $this->assertNotNull($ok);
        $this->assertTrue($ok->diagnostic);
        $this->assertFalse($ok->screenshots);
        $this->assertTrue($ok->allowsScreenshots());

        $blocked = HdbReportRedaction::fromTicket(['redactDiagnostic' => false, 'redactScreenshots' => true]);

        $this->assertNotNull($blocked);
        $this->assertFalse($blocked->allowsScreenshots());

        $this->assertNull(HdbReportRedaction::fromTicket([]));
        $this->assertNull(HdbReportRedaction::fromTicket(['redactDiagnostic' => 'true', 'redactScreenshots' => true]));
    }

    // ---------------------------------------------------- authorization gate

    /**
     * THE #1359 PROPERTY: a press keyed on ANOTHER client's ticket is refused,
     * and — the half that matters — NO REQUEST IS ISSUED AT ALL.
     *
     * Zero round trips is what stops this client being an existence oracle: a
     * caller cannot distinguish "that press is not yours" from "that press does
     * not exist" by timing or by the portal's answer, because the portal is
     * never asked. Mutation that kills it: authenticating or fetching first and
     * checking the gate on the result.
     */
    public function test_a_press_belonging_to_another_client_is_refused_before_any_request(): void
    {
        $mine = $this->keyedTicket(self::PRESS);
        $theirs = $this->keyedTicket(self::OTHER_PRESS);

        $this->fakePortal([
            HdbReportClient::FILE_TICKET => json_encode($this->ticketJson()),
            HdbReportClient::FILE_REPORT => json_encode($this->reportJson()),
        ]);

        // Viewing MY ticket, asking for THEIR press.
        $result = $this->client()->fetch($mine->id, self::OTHER_PRESS);

        $this->assertSame(HdbReportStatus::Refused, $result->status);
        $this->assertSame(HdbReportFetchRefusal::NoKeyedNote, $result->refusal);
        $this->assertFalse($result->importable());
        $this->assertNull($result->ticket);
        $this->assertNull($result->report);
        $this->assertCount(0, Http::recorded(), 'A refused fetch must not touch the network.');

        // The other ticket really does own that press — so the refusal above is
        // the binding refusing, not a press that simply does not exist.
        $this->assertSame(
            HdbReportStatus::Fetched,
            $this->client()->fetch($theirs->id, self::OTHER_PRESS)->status,
        );
    }

    /**
     * A press id that no note ever carried — the pasted-link case — is refused
     * with the SAME symbol as the cross-client case. The refusal must not be an
     * oracle for "this press exists, but not for you".
     */
    public function test_a_pasted_press_id_nobody_captured_is_refused_with_the_same_symbol(): void
    {
        $ticket = $this->keyedTicket(self::PRESS);

        $this->fakePortal([HdbReportClient::FILE_TICKET => json_encode($this->ticketJson())]);

        // A WELL-FORMED press id that no note carries. The shape matters: the
        // first draft of this case used a 5-character group and got
        // MalformedPressId, which would have proved the parser rather than the
        // binding and left the pasted-link case untested.
        $uncaptured = $this->client()->fetch($ticket->id, 'c0ffee00-dead-4bee-8000-000000000001');
        $malformed = $this->client()->fetch($ticket->id, 'not-a-press-id');

        $this->assertSame(HdbReportStatus::Refused, $uncaptured->status);
        $this->assertSame(HdbReportFetchRefusal::NoKeyedNote, $uncaptured->refusal);
        $this->assertSame(HdbReportFetchRefusal::MalformedPressId, $malformed->refusal);
        $this->assertCount(0, Http::recorded());
    }

    /**
     * The note-scoped entry point inherits the gate: a note id from ANOTHER
     * ticket does not self-authorize, even though the note itself is live and
     * keyed.
     */
    public function test_a_note_from_another_ticket_does_not_authorize_its_own_fetch(): void
    {
        $mine = $this->keyedTicket(self::PRESS);
        $theirs = $this->keyedTicket(self::OTHER_PRESS);
        $theirNote = TicketNote::where('ticket_id', $theirs->id)->firstOrFail();

        $this->fakePortal([
            HdbReportClient::FILE_TICKET => json_encode($this->ticketJson()),
            HdbReportClient::FILE_REPORT => json_encode($this->reportJson()),
        ]);

        $crossed = $this->client()->fetchForNote($mine->id, $theirNote->id);

        $this->assertSame(HdbReportStatus::Refused, $crossed->status);
        $this->assertSame(HdbReportFetchRefusal::NoKeyedNote, $crossed->refusal);
        $this->assertCount(0, Http::recorded());

        // Positive direction: on its OWN ticket the same note fetches.
        $this->assertSame(
            HdbReportStatus::Fetched,
            $this->client()->fetchForNote($theirs->id, $theirNote->id)->status,
        );
    }

    // -------------------------------------------- degraded reads must SCREAM

    /**
     * A `report.json` missing a required section is a BUG, not "that machine
     * had no event log" - docs/ARCHITECTURE.md Vendor response shapes, rule 3.
     *
     * Three properties at once, each load-bearing: the status is NOT Fetched,
     * `importable()` is false so no caller can write a false all-clear, and the
     * missing section is NAMED so an operator learns which part of the vendor's
     * shape moved.
     *
     * Mutation that kills it: a `?? []` coalesce on any required section, which
     * is the natural shape and the exact failure CIPP shipped.
     *
     * @dataProvider requiredSections
     */
    public function test_a_report_missing_a_required_section_screams_rather_than_importing(string $section): void
    {
        $ticket = $this->keyedTicket();
        $report = $this->reportJson();
        unset($report[$section]);

        $this->fakePortal([
            HdbReportClient::FILE_TICKET => json_encode($this->ticketJson()),
            HdbReportClient::FILE_REPORT => json_encode($report),
            HdbReportClient::FILE_SCREENSHOT => 'PNG',
        ]);

        $result = $this->client()->fetch($ticket->id, self::PRESS);

        $this->assertSame(HdbReportStatus::Degraded, $result->status);
        $this->assertSame(HdbReportResult::REASON_REPORT_SECTIONS_MISSING, $result->reason);
        $this->assertFalse($result->importable(), 'A degraded report must never be importable.');
        $this->assertSame([$section], $result->missingSections);

        // The payload is PRESERVED, not emptied: discarding what did arrive
        // would be its own data loss, and the evidence an operator needs.
        $this->assertIsArray($result->report);
        $this->assertNotSame([], $result->report);

        // The operator sentence has to say the import did not happen. A
        // degraded read reported in the language of success is the failure this
        // whole rule exists to prevent.
        $this->assertStringContainsString('NOT imported', $result->message());
    }

    /** @return array<string, array{string}> */
    public static function requiredSections(): array
    {
        return array_combine(
            HdbReportClient::REQUIRED_REPORT_SECTIONS,
            array_map(fn (string $s) => [$s], HdbReportClient::REQUIRED_REPORT_SECTIONS),
        );
    }

    /**
     * The distinction docs/ARCHITECTURE.md draws explicitly: a key PRESENT
     * holding an empty value is a genuine no-value and imports cleanly; a key
     * ABSENT is drift and does not.
     *
     * Without this case the section check could be satisfied by one that alarms
     * on empty sections too - which would alarm on nearly every real press
     * (`mapNet` is "often empty" per the vault note) and would be switched off
     * within a week.
     */
    public function test_a_present_but_empty_section_is_a_real_answer_and_imports(): void
    {
        $ticket = $this->keyedTicket();

        $this->fakePortal([
            HdbReportClient::FILE_TICKET => json_encode($this->ticketJson()),
            HdbReportClient::FILE_REPORT => json_encode($this->reportJson([
                'eventLog' => ['application' => [], 'system' => []],
                'software' => ['bsodList' => []],
            ])),
            HdbReportClient::FILE_SCREENSHOT => 'PNG',
        ]);

        $result = $this->client()->fetch($ticket->id, self::PRESS);

        $this->assertSame(HdbReportStatus::Fetched, $result->status, $result->reason);
        $this->assertTrue($result->importable());
        $this->assertSame([], $result->missingSections);
    }

    /**
     * A body that is not a readable JSON object is Malformed - never an empty
     * payload. The login-page case is the one that matters in production: a
     * lapsed session makes the portal answer HTML where JSON was expected, and
     * reading that as "the report is empty" is the false all-clear.
     *
     * @dataProvider unreadableBodies
     */
    public function test_an_unreadable_body_is_malformed_rather_than_empty(string $body): void
    {
        $ticket = $this->keyedTicket();

        $this->fakePortal([
            HdbReportClient::FILE_TICKET => $body,
            HdbReportClient::FILE_REPORT => json_encode($this->reportJson()),
        ]);

        $result = $this->client()->fetch($ticket->id, self::PRESS);

        $this->assertSame(HdbReportStatus::Malformed, $result->status);
        $this->assertSame(HdbReportResult::REASON_UNREADABLE_PAYLOAD, $result->reason);
        $this->assertFalse($result->importable());
        $this->assertNull($result->ticket, 'A malformed body must not produce a payload at all.');
    }

    /** @return array<string, array{string}> */
    public static function unreadableBodies(): array
    {
        return [
            'the login page' => ['<html><body><form id="theOnlyForm"></form></body></html>'],
            'truncated json' => ['{"uploadComplete": tr'],
            'a json list' => ['[{"uploadComplete": true}]'],
            'a bare scalar' => ['"ok"'],
            'json null' => ['null'],
            'an empty object' => ['{}'],
            'empty body' => [''],
        ];
    }

    /**
     * A non-2xx from the gatekeeper, after redirects, is Malformed with its own
     * reason - the portal ANSWERED, and what it answered is unusable. Never an
     * empty report.
     */
    public function test_a_portal_error_response_does_not_become_an_empty_report(): void
    {
        $ticket = $this->keyedTicket();

        $this->fakePortal([
            HdbReportClient::FILE_TICKET => json_encode($this->ticketJson()),
            HdbReportClient::FILE_REPORT => Http::response('Fatal error.', 500),
        ]);

        $result = $this->client()->fetch($ticket->id, self::PRESS);

        $this->assertSame(HdbReportStatus::Malformed, $result->status);
        $this->assertSame(HdbReportResult::REASON_UNEXPECTED_RESPONSE, $result->reason);
        $this->assertFalse($result->importable());
        $this->assertNull($result->report);
    }
}
