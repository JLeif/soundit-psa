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
     * string). Both exits to Stripe go through stripeFallback(), but only the
     * mint-failure exit asks it for the partial-balance guard: that click came
     * from the BenjiPays surface, whose balance note drops the full-amount
     * caveat, so a PARTIALLY PAID invoice must not be silently bounced to the
     * full-total Stripe page — the overpayment #1173 exists to prevent. The
     * predicate re-read exit does NOT ask for it: paysOnlineViaBenjiPays() is
     * false for every invoice while the toggle is off or no key is stored —
     * the shipped default — and on that surface the balance note names the
     * full amount itself, so refusing the click there would permanently
     * remove a working payment route and call the removal temporary. Either
     * way the client goes to the Stripe page if the invoice has one, else back
     * to the invoice with a generic flash — never a vendor message.
     */
    public function payOnline(Request $request, Invoice $invoice, BenjiPaysPayOnline $payOnline): RedirectResponse
    {
        $clientId = $request->attributes->get('portal_client_id');

        if ($invoice->client_id !== $clientId || ! in_array($invoice->status, self::PORTAL_STATUSES, true)) {
            abort(404);
        }

        if (! $invoice->paysOnlineViaBenjiPays()) {
            // The surface this click came from names the full amount itself.
            return $this->stripeFallback($invoice, guardPartialBalance: false);
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

            return $this->stripeFallback($invoice, guardPartialBalance: true);
        }

        return redirect()->away($link->url);
    }

    /**
     * The single exit to Stripe for both give-up paths.
     *
     * $guardPartialBalance is set by the mint-failure exit only. There the
     * client clicked from the BenjiPays surface, which drops the "will charge
     * the full $X" caveat because the vendor page is priced at the balance, so
     * a partially paid invoice must not be sent to the full-total Stripe page
     * without the amount named — the overpayment #1173 exists to prevent.
     *
     * The predicate re-read exit does not set it. paysOnlineViaBenjiPays() is
     * false for EVERY invoice whenever the toggle is off or no key is stored,
     * which is the shipped default and the steady state of any deployment not
     * using BenjiPays; on that surface the balance note renders the
     * full-amount caveat, so there is nothing withheld to compensate for, and
     * guarding here would refuse online payment for every partially paid
     * invoice forever while calling the refusal temporary.
     *
     * Guarded on the Stripe URL too: with no Stripe page there is no
     * full-amount route to withhold, and saying otherwise would tell the
     * client something false about their own invoice.
     */
    private function stripeFallback(Invoice $invoice, bool $guardPartialBalance): RedirectResponse
    {
        if ($guardPartialBalance && $invoice->qboPartialBalanceLog() !== null && $invoice->stripe_invoice_url) {
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
