<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Setting;
use App\Models\Sku;
use App\Models\User;
use App\Services\InvoiceVoidService;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeClientException;
use App\Services\Stripe\StripeSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Independent RED-first reproductions of the psa-bl36l R5 REVISE findings
 * (psa-f9gbv).
 *
 * Every R5 review lane REJECTED the Stripe void payment path at 115ff46
 * (main-lineage equivalent: 74e229d), and the R6 rework (726430e) landed on
 * main without fresh independent review. Each test here reproduces ONE R5
 * blocking finding through public entry points only — the push/send services,
 * the staff void route, and the invoice detail view — so this exact file runs
 * unmodified at the PRE-FIX sha (74e229d, where each reproduction FAILS) and
 * at the landed rework (where each must PASS). That RED→GREEN pair is the
 * proof the landed code resolves the finding, independent of the author's own
 * regression suite.
 *
 * Finding → test map:
 *  - psa-bl36l.1 (architecture) MF1: late push-email failure overwrites a
 *    concurrent Void's provenance with stale retry guidance
 *      → test_r5_arch_late_email_failure_cannot_overwrite_a_confirmed_voids_provenance
 *  - psa-bl36l.2 (security) MF1: the same late writer erases a PAID/live
 *    divergence alarm — the operator's only durable instruction
 *      → test_r5_security_late_email_failure_cannot_erase_a_paid_divergence_alarm
 *  - psa-bl36l.2 (security) MF2: duplicate-push compensation clears an alarm
 *    its proof does not cover (and re-points the row at the duplicate id)
 *      → test_r5_security_duplicate_push_compensation_cannot_clear_the_winning_ids_alarm
 *  - psa-bl36l.3 (product) MF1: combined void-after-boundary + send failure
 *    leaves a false durable "retry email / may not reflect this void" state
 *      → test_r5_product_combined_void_and_send_failure_leaves_no_false_durable_state
 *  - psa-bl36l.3 (product) MF2: the standalone-send Void refusal gives blanket
 *    manual-void advice that contradicts a paid invoice's real remedy
 *      → test_r5_product_send_refusal_carries_the_rows_per_cause_action_not_blanket_advice
 *  - psa-bl36l.3 (product) MF3: the Void banner claims QBO convergence from id
 *    presence and drops the proven Stripe state
 *      → test_r5_product_void_banner_renders_each_provider_from_its_own_sync_state
 *
 * The final test is not a reproduction: it pins the Stripe money path
 * (unit_amount × quantity, non-taxable amount) the brief requires verified,
 * and is expected green at BOTH shas.
 */
class StripeVoidR5ReproductionTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private function makeInvoice(array $attrs = []): Invoice
    {
        $attrs['client_id'] ??= Client::factory()->create()->id;

        $invoice = Invoice::create(array_merge([
            'invoice_number' => 'INV-R5REP-'.str_pad((string) ++self::$seq, 4, '0', STR_PAD_LEFT),
            'invoice_date' => now()->subDays(5),
            'due_date' => now()->addDays(25),
            'subtotal' => '500.00',
            'tax' => '40.00',
            'total' => '540.00',
            'total_cost' => '200.00',
            'margin' => '300.00',
            'status' => InvoiceStatus::Synced,
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

    public function test_r5_arch_late_email_failure_cannot_overwrite_a_confirmed_voids_provenance(): void
    {
        // psa-bl36l.1 R5 MF1, exactly as the architecture lane reproduced it:
        // "Push & Email Client" commits the locked result boundary; while
        // sendInvoice() is in flight the FULL staff Void route runs and its
        // propagation CONFIRMS the upstream void for the recorded id (local
        // Void/$0, URL cleared, stripe_sync_error cleared — proven
        // convergence). The in-flight send then throws. The late email-failure
        // writer must not replace that proven-convergence null with stale
        // "use Email to Client to retry" guidance — that control is absent on
        // a Void invoice and emailing a voided invoice is never advice.
        Setting::setEncrypted('stripe_secret_key', 'sk_test_x');
        $client = Client::factory()->create(['stripe_customer_id' => 'cus_r5rep']);
        $invoice = $this->makeInvoice(['client_id' => $client->id, 'status' => InvoiceStatus::Posted]);
        $staff = User::factory()->create();

        $stripe = \Mockery::mock(StripeClient::class);
        $this->app->instance(StripeClient::class, $stripe);

        $stripe->shouldReceive('createInvoice')->once()->andReturn(['id' => 'in_r5arch']);
        $stripe->shouldReceive('createInvoiceItem')->andReturn([]);
        $stripe->shouldReceive('finalizeInvoice')->once()->with('in_r5arch')
            ->andReturn(['id' => 'in_r5arch', 'status' => 'open', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/pay_r5arch', 'tax' => 4000, 'total' => 54000]);
        $stripe->shouldReceive('sendInvoice')->once()->with('in_r5arch')
            ->andReturnUsing(function () use ($invoice, $staff) {
                $this->actingAs($staff)->post(route('invoices.void', $invoice))->assertRedirect();

                throw new StripeClientException('Stripe refused: invoice is void');
            });
        $stripe->shouldReceive('getInvoice')->once()->with('in_r5arch')
            ->andReturn(['id' => 'in_r5arch', 'status' => 'open']);
        $stripe->shouldReceive('voidInvoice')->once()->with('in_r5arch')
            ->andReturn(['id' => 'in_r5arch', 'status' => 'void']);

        $caught = null;
        try {
            (new StripeSyncService($stripe))->pushInvoiceToStripe($invoice, true);
        } catch (StripeClientException $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'Expected the send failure to surface.');

        $fresh = $invoice->fresh();
        // The finding: the void's provenance outranks the email failure. After
        // PROVEN convergence the durable error must remain null.
        $this->assertNull($fresh->stripe_sync_error, 'The late email-failure writer overwrote a confirmed Void convergence.');
        // The one-time outcome must not prescribe a retry through a control
        // that does not exist on a Void invoice.
        $this->assertStringNotContainsString('Email to Client', $caught->getMessage());

        // Terminal voided money state survives the whole interleaving.
        $this->assertSame(InvoiceStatus::Void, $fresh->status);
        $this->assertNull($fresh->stripe_invoice_url);
        $this->assertSame('0.00', $fresh->total);
        $this->assertSame('0.00', $fresh->subtotal);
        $this->assertSame('540.00', $fresh->pre_void_total);
    }

    public function test_r5_security_late_email_failure_cannot_erase_a_paid_divergence_alarm(): void
    {
        // psa-bl36l.2 R5 MF1 (HIGH), the worse ordering: the concurrent Void's
        // propagation finds the upstream invoice already PAID and records the
        // load-bearing "reconcile or refund" divergence — the operator's ONLY
        // durable instruction for money that can no longer be voided upstream.
        // The in-flight send then fails. The late writer must not replace that
        // alarm with the less important email error, nor recommend emailing
        // the client again.
        Setting::setEncrypted('stripe_secret_key', 'sk_test_x');
        $client = Client::factory()->create(['stripe_customer_id' => 'cus_r5rep']);
        $invoice = $this->makeInvoice(['client_id' => $client->id, 'status' => InvoiceStatus::Posted]);
        $staff = User::factory()->create();

        $stripe = \Mockery::mock(StripeClient::class);
        $this->app->instance(StripeClient::class, $stripe);

        $stripe->shouldReceive('createInvoice')->once()->andReturn(['id' => 'in_r5sec1']);
        $stripe->shouldReceive('createInvoiceItem')->andReturn([]);
        $stripe->shouldReceive('finalizeInvoice')->once()->with('in_r5sec1')
            ->andReturn(['id' => 'in_r5sec1', 'status' => 'open', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/pay_r5sec1', 'tax' => 4000, 'total' => 54000]);
        $stripe->shouldReceive('sendInvoice')->once()->with('in_r5sec1')
            ->andReturnUsing(function () use ($invoice, $staff) {
                $this->actingAs($staff)->post(route('invoices.void', $invoice))->assertRedirect();

                throw new StripeClientException('response lost');
            });
        // The void propagation finds PAID: records reconcile/refund + throws;
        // no upstream void is possible for a paid invoice.
        $stripe->shouldReceive('getInvoice')->once()->with('in_r5sec1')
            ->andReturn(['id' => 'in_r5sec1', 'status' => 'paid']);
        $stripe->shouldNotReceive('voidInvoice');

        $caught = null;
        try {
            (new StripeSyncService($stripe))->pushInvoiceToStripe($invoice, true);
        } catch (StripeClientException $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'Expected the send failure to surface.');

        $fresh = $invoice->fresh();
        // The finding: the PAID divergence is the durable truth that survives.
        $this->assertNotNull($fresh->stripe_sync_error, 'The paid/live divergence alarm was erased.');
        $this->assertStringContainsString('already paid', $fresh->stripe_sync_error);
        $this->assertStringContainsString('reconcile or refund', $fresh->stripe_sync_error);
        $this->assertStringNotContainsString('email could not be sent', $fresh->stripe_sync_error);
        $this->assertStringNotContainsString('Email to Client', $caught->getMessage());

        $this->assertSame(InvoiceStatus::Void, $fresh->status);
        $this->assertSame('0.00', $fresh->total);
    }

    public function test_r5_security_duplicate_push_compensation_cannot_clear_the_winning_ids_alarm(): void
    {
        // psa-bl36l.2 R5 MF2 (HIGH), end to end: two pushes admitted before
        // either records. Push A's locked boundary records in_winner; a staff
        // Void then finds in_winner already PAID upstream and records the
        // reconcile/refund divergence. Push B's boundary fires with in_loser:
        // the row's link and its paid alarm belong to A's chain, so B must
        // not re-point the link at its duplicate, must compensate ONLY its own
        // created object, and its compensation proof (which covers in_loser
        // alone) must never clear in_winner's alarm. No email ever goes out.
        Setting::setEncrypted('stripe_secret_key', 'sk_test_x');
        $client = Client::factory()->create(['stripe_customer_id' => 'cus_r5rep']);
        $invoice = $this->makeInvoice(['client_id' => $client->id, 'status' => InvoiceStatus::Posted]);
        $staff = User::factory()->create();

        $stripe = \Mockery::mock(StripeClient::class);
        $this->app->instance(StripeClient::class, $stripe);

        $stripe->shouldReceive('createInvoice')->once()->andReturn(['id' => 'in_loser']);
        $stripe->shouldReceive('createInvoiceItem')->andReturn([]);
        $stripe->shouldReceive('finalizeInvoice')->once()->with('in_loser')
            ->andReturnUsing(function () use ($invoice, $staff) {
                // Concurrent push A's boundary commits first with its own id —
                // a separate model instance, exactly like another process.
                Invoice::findOrFail($invoice->id)->recordPushResult([
                    'stripe_invoice_id' => 'in_winner',
                    'stripe_invoice_url' => 'https://invoice.stripe.com/i/pay_winner',
                    'tax' => '40.00',
                    'total' => '540.00',
                    'stripe_synced_at' => now(),
                    'stripe_sync_error' => null,
                ]);

                // Staff Void: propagation finds in_winner already PAID and
                // records the durable reconcile/refund divergence.
                $this->actingAs($staff)->post(route('invoices.void', $invoice))->assertRedirect();

                return ['id' => 'in_loser', 'status' => 'open', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/pay_loser', 'tax' => 4000, 'total' => 54000];
            });
        $stripe->shouldReceive('getInvoice')->once()->with('in_winner')
            ->andReturn(['id' => 'in_winner', 'status' => 'paid']);
        // B compensates ONLY its own duplicate, never A's recorded invoice.
        $stripe->shouldReceive('voidInvoice')->once()->with('in_loser')
            ->andReturn(['id' => 'in_loser', 'status' => 'void']);
        $stripe->shouldNotReceive('sendInvoice');

        try {
            (new StripeSyncService($stripe))->pushInvoiceToStripe($invoice, true);
            $this->fail('Expected the losing push to abort loudly.');
        } catch (StripeClientException $e) {
            $this->assertStringContainsString('NOT emailed', $e->getMessage());
        }

        $fresh = $invoice->fresh();
        // The finding, part 1: the row still points at the WINNING id — the
        // duplicate never becomes the invoice's recorded link.
        $this->assertSame('in_winner', $fresh->stripe_invoice_id, 'The losing push re-pointed the row at its duplicate id.');
        // The finding, part 2: proof about in_loser cannot clear in_winner's
        // paid alarm — the exact erasure the R5 security lane demonstrated.
        $this->assertNotNull($fresh->stripe_sync_error, 'Compensating the duplicate erased the winning id\'s paid divergence alarm.');
        $this->assertStringContainsString('already paid', $fresh->stripe_sync_error);
        $this->assertStringContainsString('reconcile or refund', $fresh->stripe_sync_error);
        $this->assertStringNotContainsString('in_loser', $fresh->stripe_sync_error);

        $this->assertSame(InvoiceStatus::Void, $fresh->status);
        $this->assertSame('0.00', $fresh->total);
        // The winner's URL is deliberately RETAINED on the paid branch as an
        // audit record (payment affordances gate on isClientPayable(), never
        // on URL presence) — the point here is that the loser's chain never
        // replaces any part of the winner's.
        $this->assertSame('https://invoice.stripe.com/i/pay_winner', $fresh->stripe_invoice_url);
    }

    public function test_r5_product_combined_void_and_send_failure_leaves_no_false_durable_state(): void
    {
        // psa-bl36l.3 R5 MF1, asserted at the operator-facing surface: after
        // the combined void-after-boundary + send-failure interleaving (the
        // same one the product lane executed at 115ff46), the invoice page
        // must tell one honest story — proven convergence, no false "Stripe
        // may not reflect this void yet", no durable "email could not be
        // sent" whose prescribed Email-to-Client control does not exist on a
        // Void invoice.
        Setting::setEncrypted('stripe_secret_key', 'sk_test_x');
        $client = Client::factory()->create(['stripe_customer_id' => 'cus_r5rep']);
        $invoice = $this->makeInvoice(['client_id' => $client->id, 'status' => InvoiceStatus::Posted]);
        $staff = User::factory()->create();

        $stripe = \Mockery::mock(StripeClient::class);
        $this->app->instance(StripeClient::class, $stripe);

        $stripe->shouldReceive('createInvoice')->once()->andReturn(['id' => 'in_r5prod']);
        $stripe->shouldReceive('createInvoiceItem')->andReturn([]);
        $stripe->shouldReceive('finalizeInvoice')->once()->with('in_r5prod')
            ->andReturn(['id' => 'in_r5prod', 'status' => 'open', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/pay_r5prod', 'tax' => 4000, 'total' => 54000]);
        $stripe->shouldReceive('sendInvoice')->once()->with('in_r5prod')
            ->andReturnUsing(function () use ($invoice, $staff) {
                $this->actingAs($staff)->post(route('invoices.void', $invoice))->assertRedirect();

                throw new StripeClientException('cannot send a voided invoice');
            });
        $stripe->shouldReceive('getInvoice')->once()->with('in_r5prod')
            ->andReturn(['id' => 'in_r5prod', 'status' => 'open']);
        $stripe->shouldReceive('voidInvoice')->once()->with('in_r5prod')
            ->andReturn(['id' => 'in_r5prod', 'status' => 'void']);

        $caught = null;
        try {
            (new StripeSyncService($stripe))->pushInvoiceToStripe($invoice, true);
        } catch (StripeClientException $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'Expected the send failure to surface.');

        // The operator view shows convergence — not the contradictory pair of
        // a false divergence banner + retry advice for an absent control.
        $this->actingAs($staff)
            ->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Stripe shows this invoice as voided')
            ->assertDontSee('may not reflect this void yet')
            ->assertDontSee('email could not be sent');

        // The one-time outcome is truthful and prescribes nothing false.
        $this->assertStringNotContainsString('Email to Client', $caught->getMessage());
    }

    public function test_r5_product_send_refusal_carries_the_rows_per_cause_action_not_blanket_advice(): void
    {
        // psa-bl36l.3 R5 MF2: a local Void against an already-paid Stripe
        // invoice records "already paid … reconcile or refund". A stale
        // "Email to Client" POST then reaches the locked send authorization.
        // The refusal is correct — but its copy must surface the row's real
        // per-cause action, not blanket "if its Stripe page may still be
        // live, void it in Stripe" advice: a paid invoice cannot be voided,
        // so that copy contradicts the alert rendered on the same page.
        Setting::setEncrypted('stripe_secret_key', 'sk_test_x');
        $invoice = $this->makeInvoice([
            'stripe_invoice_id' => 'in_r5stale',
            'stripe_invoice_url' => 'https://invoice.stripe.com/i/pay_r5stale',
        ]);
        $staff = User::factory()->create();

        $stripe = \Mockery::mock(StripeClient::class);
        $this->app->instance(StripeClient::class, $stripe);

        // The staff Void's propagation finds the invoice PAID upstream.
        $stripe->shouldReceive('getInvoice')->once()->with('in_r5stale')
            ->andReturn(['id' => 'in_r5stale', 'status' => 'paid']);
        $stripe->shouldNotReceive('voidInvoice');
        $stripe->shouldNotReceive('sendInvoice');

        // A staff browser tab captured the send form BEFORE the void: its
        // controller-bound model is stale by the time the POST lands.
        $stale = Invoice::findOrFail($invoice->id);

        $this->actingAs($staff)->post(route('invoices.void', $invoice))->assertRedirect();

        try {
            (new StripeSyncService($stripe))->sendInvoiceEmail($stale);
            $this->fail('Expected the Void refusal to surface.');
        } catch (StripeClientException $e) {
            $this->assertStringContainsString('not emailed', $e->getMessage());
            // The finding: the refusal carries the durable per-cause remedy…
            $this->assertStringContainsString('reconcile or refund', $e->getMessage());
            // …never the impossible blanket manual-void instruction.
            $this->assertStringNotContainsString('may still be live', $e->getMessage());
        }

        // The refusal wrote nothing: the paid alarm is untouched.
        $fresh = $invoice->fresh();
        $this->assertStringContainsString('reconcile or refund', $fresh->stripe_sync_error);
    }

    public function test_r5_product_void_banner_renders_each_provider_from_its_own_sync_state(): void
    {
        // psa-bl36l.3 R5 MF3: dual-linked invoice, QBO void FAILED while
        // Stripe converged. The banner must not claim "QuickBooks shows this
        // invoice as $0.00" merely because qbo_invoice_id exists, and must
        // not drop the proven Stripe state because the QBO branch rendered
        // first. Each provider's convergence claim is conditioned on its OWN
        // durable sync error.
        $invoice = $this->makeInvoice([]);
        app(InvoiceVoidService::class)->void($invoice);
        Invoice::where('id', $invoice->id)->update([
            'qbo_invoice_id' => '7042',
            'qbo_sync_error' => 'QBO void failed: transport error. Void invoice #7042 manually in QuickBooks.',
            'stripe_invoice_id' => 'in_r5banner',
            'stripe_sync_error' => null,
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertDontSee('QuickBooks shows this invoice as $0.00')
            ->assertSee('QuickBooks may not reflect this void yet')
            ->assertSee('Stripe shows this invoice as voided');
    }

    public function test_money_path_push_sends_exact_unit_amount_and_quantity_to_stripe(): void
    {
        // Money-path verification required by the rework order (not an R5
        // reproduction — expected green at both the pre-fix and landed shas):
        // a taxable line reaches Stripe as unit_amount × quantity in integer
        // cents, a non-taxable line as its exact amount in cents, and the
        // finalized tax/total land back on the row.
        $client = Client::factory()->create(['stripe_customer_id' => 'cus_r5rep']);
        $invoice = $this->makeInvoice(['client_id' => $client->id, 'status' => InvoiceStatus::Posted]);

        $sku = Sku::create([
            'name' => 'Managed Endpoint',
            'sku_code' => 'R5REP-ENDPOINT',
            'unit_price' => '100.00',
            'stripe_product_id' => 'prod_r5rep',
        ]);
        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'sku_id' => $sku->id,
            'description' => 'Managed endpoints',
            'quantity' => 5,
            'unit_price' => '100.00',
            'amount' => '500.00',
            'is_taxable' => true,
            'sort_order' => 1,
        ]);
        $invoice = $invoice->fresh();

        $items = [];
        $stripe = \Mockery::mock(StripeClient::class);
        $stripe->shouldReceive('createInvoice')->once()->andReturn(['id' => 'in_r5money']);
        $stripe->shouldReceive('createInvoiceItem')->twice()
            ->andReturnUsing(function (array $itemData) use (&$items) {
                $items[] = $itemData;

                return [];
            });
        $stripe->shouldReceive('finalizeInvoice')->once()->with('in_r5money')
            ->andReturn(['id' => 'in_r5money', 'status' => 'open', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/pay_r5money', 'tax' => 8250, 'total' => 108750]);

        (new StripeSyncService($stripe))->pushInvoiceToStripe($invoice);

        // Non-taxable line: exact amount in cents, nontaxable tax code.
        $this->assertSame(50000, $items[0]['amount']);
        $this->assertSame('txcd_00000000', $items[0]['tax_code']);
        $this->assertArrayNotHasKey('quantity', $items[0]);

        // Taxable line: unit_amount × quantity in integer cents — Stripe
        // multiplies, so the unit price must be exact, not pre-multiplied.
        $this->assertSame(5, $items[1]['quantity']);
        $this->assertSame(10000, $items[1]['price_data[unit_amount]']);
        $this->assertSame('prod_r5rep', $items[1]['price_data[product]']);
        $this->assertSame('exclusive', $items[1]['price_data[tax_behavior]']);

        // Finalized tax/total read back onto the row in dollars.
        $fresh = $invoice->fresh();
        $this->assertSame('82.50', $fresh->tax);
        $this->assertSame('1087.50', $fresh->total);
        $this->assertSame(InvoiceStatus::Synced, $fresh->status);
    }
}
