<?php

namespace App\Services\Mesh;

use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Services\SyncResult;
use Illuminate\Support\Facades\Log;

class MeshLicenseSyncService
{
    public function __construct(
        private readonly MeshClient $meshClient,
    ) {}

    /**
     * Sync licenses from Mesh for all clients with a mesh_customer_id.
     */
    public function syncLicenses(?callable $onProgress = null): SyncResult
    {
        $clients = Client::whereNotNull('mesh_customer_id')
            ->operational()
            ->get();

        $result = new SyncResult;

        foreach ($clients as $client) {
            try {
                $this->syncClientLicenses($client, $result);
            } catch (\Throwable $e) {
                // By client id, never by name (client data), and by status or
                // exception class, never by the message: a MeshClientException
                // message quotes Guzzle's, which carries the request URI, the
                // host and a summary of the vendor's body (C-56).
                $reason = $e instanceof MeshClientException
                    ? $e->statusPhrase('the customer read')
                    : 'an unexpected error';
                Log::error("[MeshSync] Failed for client {$client->getKey()}: {$reason} (".$e::class.')');
                $result->errors++;
            }

            if ($onProgress) {
                $onProgress($result);
            }
        }

        // Deactivate licenses on clients that no longer have a Mesh mapping
        $result->deactivated += License::deactivateOrphaned('mesh', 'mesh_customer_id');

        return $result;
    }

    private function syncClientLicenses(Client $client, SyncResult $result): void
    {
        $customerData = $this->meshClient->getCustomer($client->mesh_customer_id);

        // A degraded read is a failure, not a skip (#5299): MeshClient::request()
        // turns an empty or undecodable 200 into [], so without this the client
        // passes silently and the sync button flashes success. Counted here
        // rather than by a throw so the log carries no exception class that
        // would read as a failed request: the request answered, the data did not.
        if (empty($customerData)) {
            $this->degradedRead($client, $result, 'the customer read returned no usable data');

            return;
        }

        // Mesh returns license counts at the customer level. An absent or
        // non-numeric field (schema drift, or an error envelope sent with 200)
        // is a failed read, never 0: a 0 would be skipped and read as success.
        $billed = $customerData['licenses_billed'] ?? null;
        if (! is_numeric($billed)) {
            $this->degradedRead($client, $result, 'the customer read had no readable licenses_billed field');

            return;
        }

        $licensesBilled = (int) $billed;
        $serviceName = $customerData['service_name'] ?? 'Mesh';
        $companyName = $customerData['company_name'] ?? $client->name;

        if ($licensesBilled <= 0) {
            Log::info("[MeshSync] Client {$client->getKey()}: 0 licenses billed, skipping");

            return;
        }

        // Upsert the license type (one per Mesh service type)
        $licenseType = LicenseType::updateOrCreate(
            [
                'vendor' => 'mesh',
                'vendor_sku_id' => $serviceName,
            ],
            [
                'name' => "Mesh: {$serviceName}",
                'is_active' => true,
            ]
        );

        // Upsert the license record for this client
        $license = License::updateOrCreate(
            [
                'license_type_id' => $licenseType->id,
                'client_id' => $client->id,
                'vendor_ref' => $client->mesh_customer_id,
            ],
            [
                'quantity' => $licensesBilled,
                'status' => ($customerData['active'] ?? true) ? 'active' : 'suspended',
                'synced_at' => now(),
            ]
        );

        if ($license->wasRecentlyCreated) {
            $result->created++;
        } else {
            $result->updated++;
        }
    }

    /**
     * Count a customer read that answered without usable data as a client
     * error. Logged by client id and a PSA-written reason only: never the
     * client's name, and never the vendor's body (C-56).
     */
    private function degradedRead(Client $client, SyncResult $result, string $reason): void
    {
        Log::error("[MeshSync] Failed for client {$client->getKey()}: {$reason}");
        $result->errors++;
    }
}
