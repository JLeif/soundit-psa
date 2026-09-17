<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\AutoElevate\AutoElevateReadException;
use App\Services\AutoElevate\AutoElevateReadService;
use App\Support\AutoElevateConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Integrations → AutoElevate → Map companies. Clones HuntressOrganizationController:
 * list vendor companies with a client dropdown each, clear-then-apply save in one transaction,
 * and an auto-match that fills only unmapped rows. Admin-only (routes carry `admin`).
 * Read-only against the vendor; no license side effects (AutoElevate bills nothing here).
 */
class AutoElevateCompanyController extends Controller
{
    public function __construct(private readonly AutoElevateReadService $reads) {}

    public function index()
    {
        if (! AutoElevateConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'AutoElevate is not configured. Add an API key first.');
        }

        try {
            $companies = $this->reads->companies();
        } catch (AutoElevateReadException $e) {
            return redirect()->route('settings.integrations')
                ->with('error', "Could not read AutoElevate companies ({$e->reason}). Nothing was changed.");
        }

        $mappedClients = Client::whereNotNull('autoelevate_company_id')
            ->get(['id', 'name', 'autoelevate_company_id'])
            ->keyBy(fn ($c) => strtolower($c->autoelevate_company_id));

        $allClients = Client::operational()->orderBy('name')->get(['id', 'name']);

        return view('settings.autoelevate-companies', [
            'companies' => $companies,
            'mappedClients' => $mappedClients,
            'allClients' => $allClients,
        ]);
    }

    public function update(Request $request)
    {
        $mappings = $request->input('mappings', []);
        if (! is_array($mappings)) {
            $mappings = [];
        }

        // Every key must be a company UUID; every non-empty value a client id.
        foreach ($mappings as $companyId => $clientId) {
            if (! is_string($companyId) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $companyId)) {
                return back()->withErrors(['mappings' => 'Invalid AutoElevate company id.']);
            }
            if ($clientId !== null && $clientId !== '' && ! ctype_digit((string) $clientId)) {
                return back()->withErrors(['mappings' => 'Invalid client id.']);
            }
        }

        DB::transaction(function () use ($mappings) {
            // Clear existing mappings, then apply the submitted set (Huntress shape).
            Client::whereNotNull('autoelevate_company_id')->update(['autoelevate_company_id' => null]);

            foreach ($mappings as $companyId => $clientId) {
                if ($clientId) {
                    Client::where('id', (int) $clientId)->update(['autoelevate_company_id' => strtolower($companyId)]);
                }
            }
        });

        $mapped = collect($mappings)->filter()->count();

        return redirect()->route('settings.autoelevate-companies.index')
            ->with('success', "Saved {$mapped} AutoElevate company mapping(s).");
    }

    /**
     * Auto-match companies to clients by normalized name (lowercase, alphanumerics only).
     * Only fills unmapped companies — never overwrites an existing mapping — and never
     * guesses: a normalized name shared by two or more clients is ambiguous and is left
     * for the manual dropdown.
     */
    public function autoMatch()
    {
        if (! AutoElevateConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'AutoElevate is not configured.');
        }

        try {
            $companies = $this->reads->companies();
        } catch (AutoElevateReadException $e) {
            return redirect()->route('settings.autoelevate-companies.index')
                ->with('error', "Could not read AutoElevate companies ({$e->reason}). Nothing was changed.");
        }

        // normalized client name → [client ids]; a bucket with 2+ entries is ambiguous.
        $buckets = [];
        foreach (Client::operational()->whereNull('autoelevate_company_id')->get(['id', 'name']) as $c) {
            $buckets[AutoElevateReadService::normalizeName($c->name)][] = $c->id;
        }

        $matched = 0;
        $ambiguous = 0;

        foreach ($companies as $company) {
            $key = AutoElevateReadService::normalizeName($company['name']);
            if ($key === '') {
                continue;
            }

            // Skip if this company is already mapped
            if (Client::where('autoelevate_company_id', $company['id'])->exists()) {
                continue;
            }

            $candidates = $buckets[$key] ?? [];
            if (count($candidates) > 1) {
                $ambiguous++;

                continue;
            }
            if (count($candidates) === 1) {
                Client::where('id', $candidates[0])->update(['autoelevate_company_id' => $company['id']]);
                // Remove from lookup so the same client isn't matched twice
                unset($buckets[$key]);
                $matched++;
            }
        }

        $message = $matched > 0
            ? "Auto-matched {$matched} company(ies) by name."
            : 'No new matches found. Companies may need manual mapping.';
        if ($ambiguous > 0) {
            $message .= " {$ambiguous} company(ies) left unmapped: more than one client shares that name.";
        }

        return redirect()->route('settings.autoelevate-companies.index')
            ->with($matched > 0 ? 'success' : 'info', $message);
    }
}
