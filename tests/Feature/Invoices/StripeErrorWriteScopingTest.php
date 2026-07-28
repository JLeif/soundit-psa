<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Setting;
use App\Models\Sku;
use App\Models\User;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeClientException;
use App\Services\Stripe\StripeSyncService;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RED-first regressions for the psa-f9gbv error-write scoping class fix —
 * one deterministic vector per newly-scoped site of the enumeration in
 * StripeSyncService's class docblock (security R6 REVISE @ 1cc9213: the
 * pre-boundary exception handler wrote stripe_sync_error on every non-Void
 * row, status-scoped but NOT attempt/Stripe-identity-scoped).
 *
 * Vector → enumeration site map (each test named test_vN_*):
 *  - V1 → W2: a losing push whose CREATE fails after a concurrent winner
 *    recorded must not overwrite the winner's clean provenance (the security
 *    lane's exact deterministic repro: expected null, observed "losing push
 *    failed before create").
 *  - V2 → W1: the pre-flight "no Stripe customer linked" writer admitted on
 *    an unlinked row must not write its config complaint over a winner that
 *    recorded while the loser's client relation was being loaded.
 *  - V3 → W2: a finalize whose RESPONSE is lost after a concurrent winner
 *    recorded leaves this attempt's created invoice live upstream — the
 *    winner's provenance stays untouched and the EXACT created id is voided
 *    upstream (no durable write on the winner's chain).
 *  - V4 → W2: same lost-finalize, racing a staff VOID instead of a winner —
 *    the void path's provenance stays untouched and the created id is
 *    voided upstream (an open payable page for a voided invoice is the worst
 *    divergence this surface guards against).
 *  - V5 → W2: no concurrency at all — an item-stage failure (SKU without a
 *    Stripe product id) after CREATE succeeded compensates the created DRAFT
 *    via DELETE and records an honest durable message on the attempt's own
 *    row (cause + compensation outcome), never a silent orphan.
 *  - V6 → W2: when compensation of the created id CANNOT be confirmed and
 *    the row moved on (winner + paid-divergence alarm), the orphan alarm is
 *    APPENDED idempotently — never replacing the winner's alarm (the R6
 *    "two alarms are two truths" append protocol, applied pre-boundary).
 *  - V7a/V7b/V7c/V7d → W2 wire seam: createInvoice carries a per-attempt
 *    Stripe idempotency key (V7b service pin); StripeClient sends it as the
 *    Idempotency-Key header and retries an ambiguous transport failure ONLY
 *    when a key makes the retry safe (V7a with / V7c without); deleteInvoice
 *    issues DELETE /v1/invoices/{id} for draft compensation (V7d).
 *    Vendor shape: https://docs.stripe.com/api/idempotent_requests (header
 *    `Idempotency-Key`) and https://docs.stripe.com/api/invoices/delete
 *    (draft-only DELETE; response carries `"deleted": true`).
 *
 * RED-first protocol (manager requirement, so-wisp-f790a685da): this exact
 * file was executed at the pre-fix tree (364bb14 = 1cc9213 + evidence tests)
 * where every vector FAILS, then at the fix commit where every vector
 * passes. The failures prove each test CAN fail — the positive control the
 * polecat standard requires.
 */
class StripeErrorWriteScopingTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private function makeInvoice(array $attrs = []): Invoice
    {
        $attrs['client_id'] ??= Client::factory()->create()->id;

        $invoice = Invoice::create(array_merge([
            'invoice_number' => 'INV-EWS-'.str_pad((string) ++self::$seq, 4, '0', STR_PAD_LEFT),
            'invoice_date' => now()->subDays(5),
            'due_date' => now()->addDays(25),
            'subtotal' => '500.00',
            'tax' => '40.00',
            'total' => '540.00',
            'total_cost' => '200.00',
            'margin' => '300.00',
            'status' => InvoiceStatus::Posted,
        ], $attrs));

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Managed services',
            'quantity' => 5,
            'unit_price' => '100.00',
            'unit_cost' => '40.00',
            'amount' => '500.00',
            'cost_amount' => '200.00',
            // Non-taxable so the push takes the amount path — no SKU needed.
            'is_taxable' => false,
            'sort_order' => 0,
        ]);

        return $invoice->fresh();
    }

    /**
     * A concurrent winner's committed boundary result, exactly like another
     * process: a separate model instance records its own id, URL, read-back
     * money, and the winner's clean (null) provenance.
     */
    private function commitConcurrentWinner(Invoice $invoice): void
    {
        Invoice::findOrFail($invoice->id)->recordPushResult([
            'stripe_invoice_id' => 'in_winner',
            'stripe_invoice_url' => 'https://invoice.stripe.com/i/pay_winner',
            'tax' => '40.00',
            'total' => '540.00',
            'stripe_synced_at' => now(),
            'stripe_sync_error' => null,
        ]);
    }

    public function test_v1_create_failure_after_a_concurrent_winner_cannot_overwrite_the_winners_clean_provenance(): void
    {
        // The security R6 lane's deterministic exact-SHA reproduction: push B
        // passes locked admission with no Stripe id; while B is inside
        // createInvoice(), push A commits recordPushResult() with in_winner,
        // its hosted URL, Synced status, and stripe_sync_error = null; B then
        // throws. The final row must retain the winner's id/URL/status AND its
        // clean provenance — B created nothing payable and owns nothing on
        // this row.
        Setting::setEncrypted('stripe_secret_key', 'sk_test_x');
        $client = Client::factory()->create(['stripe_customer_id' => 'cus_ews']);
        $invoice = $this->makeInvoice(['client_id' => $client->id]);

        $stripe = \Mockery::mock(StripeClient::class);
        $this->app->instance(StripeClient::class, $stripe);

        $stripe->shouldReceive('createInvoice')->once()
            ->andReturnUsing(function () use ($invoice) {
                $this->commitConcurrentWinner($invoice);

                throw new StripeClientException('losing push failed before create');
            });
        $stripe->shouldNotReceive('sendInvoice');

        try {
            (new StripeSyncService($stripe))->pushInvoiceToStripe($invoice);
            $this->fail('Expected the losing create failure to surface.');
        } catch (StripeClientException $e) {
            $this->assertStringContainsString('losing push failed before create', $e->getMessage());
        }

        $fresh = $invoice->fresh();
        $this->assertSame('in_winner', $fresh->stripe_invoice_id);
        $this->assertSame('https://invoice.stripe.com/i/pay_winner', $fresh->stripe_invoice_url);
        $this->assertSame(InvoiceStatus::Synced, $fresh->status);
        // The finding: the loser's unrelated error must not replace the
        // winner's clean provenance.
        $this->assertNull($fresh->stripe_sync_error, 'A losing pre-boundary failure overwrote the winning push\'s clean provenance.');
    }

    public function test_v2_config_refusal_after_a_concurrent_winner_cannot_overwrite_the_winners_clean_provenance(): void
    {
        // Same clobber class, different pre-boundary writer: push B admits on
        // an unlinked row, then stalls while its (unmapped) client relation
        // loads; a concurrent winner records meanwhile. B's "no Stripe
        // customer linked" config complaint must not land on the winner's
        // row. The winner is committed from inside the Client::retrieved
        // model event — the only observable seam inside that window.
        Setting::setEncrypted('stripe_secret_key', 'sk_test_x');
        $invoice = $this->makeInvoice(); // client has NO stripe_customer_id

        $fired = false;
        Client::retrieved(function () use (&$fired, $invoice) {
            if ($fired) {
                return;
            }
            $fired = true;
            $this->commitConcurrentWinner($invoice);
        });

        $stripe = \Mockery::mock(StripeClient::class);
        $this->app->instance(StripeClient::class, $stripe);
        $stripe->shouldNotReceive('createInvoice');

        try {
            (new StripeSyncService($stripe))->pushInvoiceToStripe($invoice);
            $this->fail('Expected the config refusal to surface.');
        } catch (StripeClientException $e) {
            $this->assertStringContainsString('no Stripe customer linked', $e->getMessage());
        }

        $this->assertTrue($fired, 'The concurrent winner never committed — the vector did not execute.');

        $fresh = $invoice->fresh();
        $this->assertSame('in_winner', $fresh->stripe_invoice_id);
        $this->assertSame(InvoiceStatus::Synced, $fresh->status);
        $this->assertNull($fresh->stripe_sync_error, 'The config-refusal writer overwrote the winning push\'s clean provenance.');
    }

    public function test_v3_lost_finalize_after_a_concurrent_winner_compensates_the_exact_created_id_and_leaves_the_winner_untouched(): void
    {
        // Push B creates in_loser; while its finalize round-trip is in
        // flight a concurrent winner records in_winner; B's finalize then
        // throws with the RESPONSE LOST — upstream, in_loser may be open and
        // payable with no row pointing at it. B must void exactly in_loser,
        // never touch in_winner, and write nothing durable on the winner's
        // chain once compensation is confirmed.
        Setting::setEncrypted('stripe_secret_key', 'sk_test_x');
        $client = Client::factory()->create(['stripe_customer_id' => 'cus_ews']);
        $invoice = $this->makeInvoice(['client_id' => $client->id]);

        $stripe = \Mockery::mock(StripeClient::class);
        $this->app->instance(StripeClient::class, $stripe);

        $stripe->shouldReceive('createInvoice')->once()->andReturn(['id' => 'in_loser']);
        $stripe->shouldReceive('createInvoiceItem')->andReturn([]);
        $stripe->shouldReceive('finalizeInvoice')->once()->with('in_loser')
            ->andReturnUsing(function () use ($invoice) {
                $this->commitConcurrentWinner($invoice);

                throw new StripeClientException('finalize response lost');
            });
        // Compensation resolves the ambiguity for the EXACT created id: the
        // lost finalize actually succeeded upstream, so in_loser is open.
        $stripe->shouldReceive('getInvoice')->once()->with('in_loser')
            ->andReturn(['id' => 'in_loser', 'status' => 'open']);
        $stripe->shouldReceive('voidInvoice')->once()->with('in_loser')
            ->andReturn(['id' => 'in_loser', 'status' => 'void']);
        $stripe->shouldNotReceive('sendInvoice');

        try {
            (new StripeSyncService($stripe))->pushInvoiceToStripe($invoice);
            $this->fail('Expected the losing push to abort loudly.');
        } catch (StripeClientException $e) {
            $this->assertStringContainsString('finalize response lost', $e->getMessage());
            $this->assertStringContainsString('in_loser', $e->getMessage());
            $this->assertStringContainsString('NOT emailed', $e->getMessage());
        }

        $fresh = $invoice->fresh();
        $this->assertSame('in_winner', $fresh->stripe_invoice_id);
        $this->assertSame('https://invoice.stripe.com/i/pay_winner', $fresh->stripe_invoice_url);
        $this->assertSame(InvoiceStatus::Synced, $fresh->status);
        $this->assertNull($fresh->stripe_sync_error, 'A losing pre-boundary failure wrote onto the winning push\'s chain.');
    }

    public function test_v4_lost_finalize_racing_a_staff_void_compensates_the_created_id_and_preserves_the_void_paths_provenance(): void
    {
        // The void-race sibling: the full staff Void route commits while the
        // finalize round-trip is in flight (the row is unlinked, so the void
        // propagates nothing and leaves clean provenance). The lost finalize
        // means this attempt's created invoice may be OPEN AND PAYABLE for an
        // invoice that is now void — the worst divergence on this surface.
        // The created id must be voided upstream; the void path's provenance
        // must stay exactly as the void left it.
        Setting::setEncrypted('stripe_secret_key', 'sk_test_x');
        $client = Client::factory()->create(['stripe_customer_id' => 'cus_ews']);
        $invoice = $this->makeInvoice(['client_id' => $client->id]);
        $staff = User::factory()->create();

        $stripe = \Mockery::mock(StripeClient::class);
        $this->app->instance(StripeClient::class, $stripe);

        $stripe->shouldReceive('createInvoice')->once()->andReturn(['id' => 'in_voidrace']);
        $stripe->shouldReceive('createInvoiceItem')->andReturn([]);
        $stripe->shouldReceive('finalizeInvoice')->once()->with('in_voidrace')
            ->andReturnUsing(function () use ($invoice, $staff) {
                $this->actingAs($staff)->post(route('invoices.void', $invoice))->assertRedirect();

                throw new StripeClientException('finalize response lost');
            });
        $stripe->shouldReceive('getInvoice')->once()->with('in_voidrace')
            ->andReturn(['id' => 'in_voidrace', 'status' => 'open']);
        $stripe->shouldReceive('voidInvoice')->once()->with('in_voidrace')
            ->andReturn(['id' => 'in_voidrace', 'status' => 'void']);
        $stripe->shouldNotReceive('sendInvoice');

        try {
            (new StripeSyncService($stripe))->pushInvoiceToStripe($invoice);
            $this->fail('Expected the aborted push to surface.');
        } catch (StripeClientException $e) {
            $this->assertStringContainsString('finalize response lost', $e->getMessage());
            $this->assertStringContainsString('in_voidrace', $e->getMessage());
        }

        $fresh = $invoice->fresh();
        // The void path owns the row: terminal Void money state, no recorded
        // id (the boundary never ran), and the void's clean provenance —
        // nothing durable from the aborted attempt.
        $this->assertSame(InvoiceStatus::Void, $fresh->status);
        $this->assertNull($fresh->stripe_invoice_id);
        $this->assertNull($fresh->stripe_sync_error, 'The aborted push wrote onto the void path\'s provenance.');
        $this->assertSame('0.00', $fresh->total);
        $this->assertSame('540.00', $fresh->pre_void_total);
    }

    public function test_v5_item_stage_failure_deletes_the_created_draft_and_records_an_honest_message_on_the_attempts_own_row(): void
    {
        // No concurrency at all: CREATE succeeds, then the item stage fails
        // (taxable line whose SKU has no Stripe product id — a deterministic
        // in-process failure between create and finalize). Before this fix
        // the created DRAFT was orphaned upstream forever with nothing
        // recording its existence. The compensation must DELETE the draft
        // (drafts cannot be voided) and the durable error on the attempt's
        // own row must state both the cause and the compensation outcome.
        Setting::setEncrypted('stripe_secret_key', 'sk_test_x');
        $client = Client::factory()->create(['stripe_customer_id' => 'cus_ews']);
        $invoice = $this->makeInvoice(['client_id' => $client->id]);

        $sku = Sku::create([
            'name' => 'Unmapped product',
            'sku_code' => 'EWS-UNMAPPED',
            'unit_price' => '100.00',
            // No stripe_product_id — resolveStripeProductId() throws.
        ]);
        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'sku_id' => $sku->id,
            'description' => 'Taxable line without a Stripe product',
            'quantity' => 1,
            'unit_price' => '100.00',
            'amount' => '100.00',
            'is_taxable' => true,
            'sort_order' => 1,
        ]);
        $invoice = $invoice->fresh();

        $stripe = \Mockery::mock(StripeClient::class);
        $this->app->instance(StripeClient::class, $stripe);

        $stripe->shouldReceive('createInvoice')->once()->andReturn(['id' => 'in_draft5']);
        // The non-taxable base line posts fine; the failure hits when the
        // loop reaches the taxable line with the unmapped SKU.
        $stripe->shouldReceive('createInvoiceItem')->andReturn([]);
        // The created invoice was never finalized: it is a DRAFT upstream,
        // and Stripe deletes drafts rather than voiding them.
        $stripe->shouldReceive('getInvoice')->once()->with('in_draft5')
            ->andReturn(['id' => 'in_draft5', 'status' => 'draft']);
        $stripe->shouldReceive('deleteInvoice')->once()->with('in_draft5')
            ->andReturn(['id' => 'in_draft5', 'object' => 'invoice', 'deleted' => true]);
        $stripe->shouldNotReceive('voidInvoice');
        $stripe->shouldNotReceive('finalizeInvoice');

        try {
            (new StripeSyncService($stripe))->pushInvoiceToStripe($invoice);
            $this->fail('Expected the item-stage failure to surface.');
        } catch (StripeClientException $e) {
            $this->assertStringContainsString('missing a Stripe product ID', $e->getMessage());
            $this->assertStringContainsString('in_draft5', $e->getMessage());
        }

        $fresh = $invoice->fresh();
        // The row is still the attempt's own (live, no id recorded): the
        // failure is the newest truth, stated honestly with its compensation.
        $this->assertNull($fresh->stripe_invoice_id);
        $this->assertNotNull($fresh->stripe_sync_error);
        $this->assertStringContainsString('missing a Stripe product ID', $fresh->stripe_sync_error);
        $this->assertStringContainsString('in_draft5', $fresh->stripe_sync_error);
        $this->assertStringContainsString('deleted', $fresh->stripe_sync_error);
    }

    public function test_v6_unconfirmed_compensation_on_a_moved_on_row_appends_and_never_replaces_the_winners_alarm(): void
    {
        // The worst composite: a winner recorded in_winner; a staff Void then
        // found in_winner already PAID upstream and recorded the operator's
        // only durable instruction ("reconcile or refund"). The losing
        // attempt's finalize is lost AND its compensating void of in_loser
        // fails — in_loser may still be live. That orphan alarm must be
        // APPENDED (idempotently, keyed on in_loser), never replacing the
        // winner's paid-divergence alarm: two alarms are two truths.
        Setting::setEncrypted('stripe_secret_key', 'sk_test_x');
        $client = Client::factory()->create(['stripe_customer_id' => 'cus_ews']);
        $invoice = $this->makeInvoice(['client_id' => $client->id]);
        $staff = User::factory()->create();

        $stripe = \Mockery::mock(StripeClient::class);
        $this->app->instance(StripeClient::class, $stripe);

        $stripe->shouldReceive('createInvoice')->once()->andReturn(['id' => 'in_loser']);
        $stripe->shouldReceive('createInvoiceItem')->andReturn([]);
        $stripe->shouldReceive('finalizeInvoice')->once()->with('in_loser')
            ->andReturnUsing(function () use ($invoice, $staff) {
                $this->commitConcurrentWinner($invoice);
                // Staff Void: propagation finds in_winner already PAID and
                // records the durable reconcile/refund divergence.
                $this->actingAs($staff)->post(route('invoices.void', $invoice))->assertRedirect();

                throw new StripeClientException('finalize response lost');
            });
        $stripe->shouldReceive('getInvoice')->once()->with('in_winner')
            ->andReturn(['id' => 'in_winner', 'status' => 'paid']);
        $stripe->shouldReceive('getInvoice')->once()->with('in_loser')
            ->andReturn(['id' => 'in_loser', 'status' => 'open']);
        // The compensating void of the orphan FAILS — unconfirmed.
        $stripe->shouldReceive('voidInvoice')->once()->with('in_loser')
            ->andThrow(new StripeClientException('rate limited'));
        $stripe->shouldNotReceive('sendInvoice');

        try {
            (new StripeSyncService($stripe))->pushInvoiceToStripe($invoice);
            $this->fail('Expected the aborted push to surface.');
        } catch (StripeClientException $e) {
            $this->assertStringContainsString('in_loser', $e->getMessage());
        }

        $fresh = $invoice->fresh();
        // The row still points at the winner and keeps the winner's alarm…
        $this->assertSame('in_winner', $fresh->stripe_invoice_id);
        $this->assertNotNull($fresh->stripe_sync_error);
        $this->assertStringContainsString('reconcile or refund', $fresh->stripe_sync_error);
        // …with the orphan alarm APPENDED, not replacing it.
        $this->assertStringContainsString(' ALSO: ', $fresh->stripe_sync_error);
        $this->assertStringContainsString('in_loser', $fresh->stripe_sync_error);
        $this->assertStringContainsString('may still be live', $fresh->stripe_sync_error);
    }

    // ── V7: the wire seam (StripeClient) ──

    /** Build a StripeClient over a Guzzle MockHandler, capturing requests. */
    private function wireClient(array $queue, array &$history): StripeClient
    {
        $mock = new MockHandler($queue);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        return new StripeClient(['secret_key' => 'sk_test'], new \GuzzleHttp\Client(['handler' => $stack]));
    }

    public function test_v7a_create_with_idempotency_key_sends_the_header_and_retries_an_ambiguous_transport_failure_with_the_same_key(): void
    {
        // Vendor shape: https://docs.stripe.com/api/idempotent_requests —
        // POSTs carrying an Idempotency-Key are safely retryable; Stripe
        // replays the original result instead of creating a second object.
        // A transport failure with no response (request may or may not have
        // reached Stripe) is exactly the ambiguity the key exists for.
        $history = [];
        $client = $this->wireClient([
            new ConnectException('connection dropped', new Request('POST', '/v1/invoices')),
            new Response(200, [], json_encode(['id' => 'in_keyed'])),
        ], $history);

        $result = $client->createInvoice(['customer' => 'cus_x'], 'psa-attempt-key-1');

        $this->assertSame('in_keyed', $result['id']);
        $this->assertCount(2, $history, 'Expected the keyed create to retry the ambiguous transport failure.');
        foreach ($history as $exchange) {
            $this->assertSame('psa-attempt-key-1', $exchange['request']->getHeaderLine('Idempotency-Key'));
        }
    }

    public function test_v7b_the_push_path_passes_a_per_attempt_idempotency_key_to_create(): void
    {
        // Service-level pin: without a key at the call site, the wire-level
        // retry safety never engages. Two pushes must not share a key (a
        // shared key would make Stripe replay one attempt's invoice into the
        // other, doubling line items through the separate item POSTs).
        Setting::setEncrypted('stripe_secret_key', 'sk_test_x');
        $client = Client::factory()->create(['stripe_customer_id' => 'cus_ews']);

        $keys = [];
        foreach (['a', 'b'] as $round) {
            $invoice = $this->makeInvoice(['client_id' => $client->id]);

            $stripe = \Mockery::mock(StripeClient::class);
            $this->app->instance(StripeClient::class, $stripe);
            $stripe->shouldReceive('createInvoice')->once()
                ->andReturnUsing(function (array $data, ?string $key = null) use (&$keys, $round) {
                    $keys[$round] = $key;

                    return ['id' => 'in_key_'.$round];
                });
            $stripe->shouldReceive('createInvoiceItem')->andReturn([]);
            $stripe->shouldReceive('finalizeInvoice')->once()
                ->andReturn(['id' => 'in_key_'.$round, 'status' => 'open', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/pay_'.$round, 'tax' => 4000, 'total' => 54000]);

            (new StripeSyncService($stripe))->pushInvoiceToStripe($invoice);
        }

        $this->assertNotNull($keys['a'], 'The push path passed no idempotency key to createInvoice.');
        $this->assertNotNull($keys['b'], 'The push path passed no idempotency key to createInvoice.');
        $this->assertNotSame($keys['a'], $keys['b'], 'Two push attempts shared an idempotency key.');
    }

    public function test_v7c_an_unkeyed_request_is_not_retried_on_a_transport_failure(): void
    {
        // Without a key a replay is NOT safe (an unkeyed retry of a create
        // that actually succeeded upstream would mint a second object), so
        // the ambiguous-failure retry must engage only when a key is present.
        $history = [];
        $client = $this->wireClient([
            new ConnectException('connection dropped', new Request('POST', '/v1/invoices')),
        ], $history);

        try {
            $client->createInvoice(['customer' => 'cus_x']);
            $this->fail('Expected the unkeyed transport failure to surface.');
        } catch (StripeClientException $e) {
            $this->assertStringContainsString('connection dropped', $e->getMessage());
        }

        $this->assertCount(1, $history, 'An unkeyed request must not be replayed.');
    }

    public function test_v7d_delete_invoice_issues_a_delete_for_the_exact_draft(): void
    {
        // Vendor shape: https://docs.stripe.com/api/invoices/delete —
        // drafts are DELETEd (voiding is for finalized invoices only) and a
        // successful deletion responds with `"deleted": true`.
        $history = [];
        $client = $this->wireClient([
            new Response(200, [], json_encode(['id' => 'in_draft', 'object' => 'invoice', 'deleted' => true])),
        ], $history);

        $result = $client->deleteInvoice('in_draft');

        $this->assertTrue($result['deleted']);
        $this->assertCount(1, $history);
        $this->assertSame('DELETE', $history[0]['request']->getMethod());
        $this->assertSame('/v1/invoices/in_draft', $history[0]['request']->getUri()->getPath());
    }
}
