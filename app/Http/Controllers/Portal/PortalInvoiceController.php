<?php

namespace App\Http\Controllers\Portal;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\BenjiPays\BenjiPaysException;
use App\Services\BenjiPays\BenjiPaysPayOnline;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
     * On any mint failure the client goes to the Stripe hosted page if the
     * invoice has one, else back to the invoice with a generic flash — never
     * a vendor message.
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
        } catch (BenjiPaysException) {
            return $this->stripeFallback($invoice);
        }

        return redirect()->away($link->url);
    }

    private function stripeFallback(Invoice $invoice): RedirectResponse
    {
        if ($invoice->stripe_invoice_url && $invoice->status->isClientPayable()) {
            return redirect()->away($invoice->stripe_invoice_url);
        }

        return redirect()->route('portal.invoices.show', $invoice)
            ->with('error', 'Online payment is temporarily unavailable. Please try again later or contact us.');
    }
}
