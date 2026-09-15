<?php

namespace App\Services\BenjiPays;

use App\Models\Invoice;

class BenjiPaysInvoiceBalance
{
    public function __construct(private BenjiPaysClient $client) {}

    public function read(Invoice $invoice): InvoiceBalance
    {
        $id = $invoice->qbo_invoice_id;
        if ($id === null || trim((string) $id) === '') {
            return new InvoiceBalance(null, null, null, 'missing_accounting_id');
        }
        try {
            return InvoiceBalance::fromInvoice($this->client->invoice((string) $id));
        } catch (BenjiPaysException $e) {
            return new InvoiceBalance(null, null, null, $e->reason);
        }
    }
}
