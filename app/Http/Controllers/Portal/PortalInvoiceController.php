<?php

namespace App\Http\Controllers\Portal;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\BenjiPays\BenjiPaysException;
use App\Services\BenjiPays\BenjiPaysPayOnline;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class PortalInvoiceController extends Controller
{
    /** Statuses visible to portal clients. */
    private const PORTAL_STATUSES = [
        InvoiceStatus::Posted,
        InvoiceStatus::Synced,
        InvoiceStatus::Paid,
    ];

    public function index(Request $request): View
    {
        $clientId = $request->attributes->get('portal_client_id');

        $invoices = Invoice::where('client_id', $clientId)
            ->whereIn('status', self::PORTAL_STATUSES)
            // #1173: the row's "partially paid" note reads the last
            // QBO-sourced status change. Without this it is one query per
            // invoice on every page of the list.
            ->with('latestQboStatusChange')
            ->orderByDesc('invoice_date')
            ->paginate(25);

        return view('portal.invoices.index', compact('invoices'));
    }

    public function show(Request $request, Invoice $invoice): View
    {
        $clientId = $request->attributes->get('portal_client_id');

        if ($invoice->client_id !== $clientId) {
            abort(403);
        }

        if (! in_array($invoice->status, self::PORTAL_STATUSES, true)) {
            abort(404);
        }

        $invoice->load(['lines', 'latestQboStatusChange']);

        return view('portal.invoices.show', compact('invoice'));
    }

    /**
     * Pay Online via a BenjiPays applied link (#2065): mint (or reuse the
     * cached) link for this invoice and send the client to it.
     *
     * Ownership is 404, not 403: this route is reached only by the button,
     * and an invoice id from another client's ledger should read as "no such
     * invoice" to a probe rather than confirm it exists. The BenjiPays path
     * is re-checked here (toggle, QBO id, status) so a form submitted after
     * the toggle was turned off falls back to Stripe like the button would.
     *
     * A mint failure is logged (reason and status only, never a vendor
     * string). BOTH exits to Stripe — a failed mint and the predicate re-read
     * above — go through stripeFallback(), which carries the one guard that
     * matters here: a PARTIALLY PAID invoice is never silently bounced to the
     * Stripe hosted page, because that page is priced at the full total and
     * the balance note drops the full-amount caveat on this path, so the
     * client would be one click from the overpayment #1173 exists to prevent.
     * Putting the guard on only the mint-failure exit left a stale form,
     * clicked after a toggle-off or a cleared key, doing exactly what the
     * other exit refuses to do. Otherwise the client goes to the Stripe page
     * if the invoice has one, else back to the invoice with a generic flash —
     * never a vendor message.
     */
    public function payOnline(Request $request, Invoice $invoice, BenjiPaysPayOnline $payOnline): RedirectResponse
    {
        $clientId = $request->attributes->get('portal_client_id');

        if ($invoice->client_id !== $clientId || ! in_array($invoice->status, self::PORTAL_STATUSES, true)) {
            abort(404);
        }

        if (! $invoice->paysOnlineViaBenjiPays()) {
            return $this->stripeFallback($invoice);
        }

        try {
            $link = $payOnline->linkFor($invoice);
        } catch (BenjiPaysException $e) {
            // Status-only: reason and HTTP status, never a vendor string. Without
            // this an operator has no record that Pay Online failed for a client.
            Log::warning('[BenjiPays] Applied link mint failed for portal Pay Online', [
                'invoice_id' => $invoice->getKey(),
                'reason' => $e->reason,
                'http_status' => $e->httpStatus,
            ]);

            return $this->stripeFallback($invoice);
        }

        return redirect()->away($link->url);
    }

    /**
     * The single exit to Stripe: every path that gives up on the BenjiPays
     * link lands here, so the partial-balance guard cannot be honoured by one
     * caller and skipped by another.
     *
     * The Stripe page charges the FULL total and this surface no longer says
     * so (the BenjiPays page is priced at the balance), so a partially paid
     * invoice must not be sent there without the amount — whether the mint
     * failed or the predicate re-read turned false under a stale form.
     * Guarded on the Stripe URL too: with no Stripe page there is no
     * full-amount route to withhold, and saying otherwise would tell the
     * client something false about their own invoice.
     */
    private function stripeFallback(Invoice $invoice): RedirectResponse
    {
        if ($invoice->qboPartialBalanceLog() !== null && $invoice->stripe_invoice_url) {
            return redirect()->route('portal.invoices.show', $invoice)
                ->with('error', 'Online payment for the remaining balance is temporarily unavailable. Paying online would charge the full $'
                    .number_format((float) $invoice->total, 2)
                    .', so we have not sent you there — please try again later, or contact us to settle just the balance.');
        }

        if ($invoice->stripe_invoice_url && $invoice->status->isClientPayable()) {
            return redirect()->away($invoice->stripe_invoice_url);
        }

        return redirect()->route('portal.invoices.show', $invoice)
            ->with('error', 'Online payment is temporarily unavailable. Please try again later or contact us.');
    }
}
