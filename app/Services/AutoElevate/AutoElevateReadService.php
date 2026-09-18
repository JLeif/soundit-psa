<?php

namespace App\Services\AutoElevate;

use Carbon\CarbonImmutable;

/**
 * Stage 2 read service: companies and computers, paged to completion, each row proven
 * against the vendor's documented shape before it becomes a screen truth.
 *
 * Producer: AutoElevate Partner API (Beta) 1.0.0 OpenAPI, components/schemas/Company and
 * Computer (verified 2026-09-17), corroborated by a read-only production probe the same day:
 *   Company  {id: uuid, name: string, initials: string|null, managementSystemCompanyId:
 *             string|null, createdAt: int ms, updatedAt: int ms}
 *   Computer {id: uuid, machineName: string|null, operatingSystem: {name: string|null,
 *             version: string|null}|null, locationId: uuid, companyId: uuid,
 *             elevationMode: audit|live|policy|technicianBypass|null,
 *             lastCheckedInAt: int ms|null, createdAt: int ms, updatedAt: int ms}
 * Timestamps are EPOCH MILLISECONDS, never ISO strings or seconds.
 * `managementSystemCompanyId` is a partner-supplied free string (usually the company name
 * again) and is NOT a usable foreign key; matching is by normalized name + manual override.
 */
class AutoElevateReadService
{
    public const ELEVATION_MODES = ['audit', 'live', 'policy', 'technicianBypass'];

    /** Hard bound on pages per read: 50 × 200 = 10,000 rows, far above any measured count. */
    public const MAX_PAGES = 50;

    /**
     * Plausibility floor for a vendor timestamp: 2000-01-01T00:00:00Z in epoch ms.
     * AutoElevate did not exist before it, so nothing it reports can predate it. Its real
     * job is unit detection: a SECONDS value for any date this century (~1.7e9) read as
     * milliseconds lands in January 1970 — comfortably under this floor — so the commonest
     * unit error is refused instead of rendering as a plausible-looking 1970 check-in.
     */
    public const PLAUSIBLE_FLOOR_MS = 946684800000;

    public function __construct(private readonly AutoElevateClient $client) {}

    /**
     * Every company, sorted by name. Rows are {id, name} only.
     *
     * @return list<array{id: string, name: string}>
     *
     * @throws AutoElevateReadException
     */
    public function companies(): array
    {
        $rows = [];
        foreach ($this->allItems('/api/v1/companies', []) as $item) {
            $id = $item['id'] ?? null;
            $name = $item['name'] ?? null;
            if (! is_string($id) || ! $this->isUuid($id) || ! is_string($name)) {
                throw new AutoElevateReadException('row_drift');
            }
            $rows[] = ['id' => strtolower($id), 'name' => $name];
        }
        usort($rows, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * Every computer scoped to one company, normalized for display.
     *
     * @return list<array{id: string, machine_name: ?string, os_name: ?string, os_version: ?string,
     *                    elevation_mode: ?string, elevation_mode_known: bool, last_checked_in_at: ?CarbonImmutable}>
     *
     * @throws AutoElevateReadException
     */
    public function computersForCompany(string $companyId): array
    {
        if (! $this->isUuid($companyId)) {
            throw new AutoElevateReadException('invalid_company_id');
        }

        $rows = [];
        foreach ($this->allItems('/api/v1/computers', ['companyId' => $companyId]) as $item) {
            $rows[] = $this->normalizeComputer($item, $companyId);
        }
        usort($rows, fn ($a, $b) => strcasecmp((string) $a['machine_name'], (string) $b['machine_name']));

        return $rows;
    }

    /**
     * One documented Computer row → display row. Every consumed field is type-proven;
     * a wrong type is drift for the whole read (never filtered into apparent truth).
     *
     * @param  array<string, mixed>  $item
     * @return array{id: string, machine_name: ?string, os_name: ?string, os_version: ?string,
     *               elevation_mode: ?string, elevation_mode_known: bool, last_checked_in_at: ?CarbonImmutable}
     */
    public function normalizeComputer(array $item, string $expectedCompanyId): array
    {
        foreach (['id', 'machineName', 'operatingSystem', 'companyId', 'elevationMode', 'lastCheckedInAt'] as $required) {
            if (! array_key_exists($required, $item)) {
                throw new AutoElevateReadException('row_drift');
            }
        }

        $id = $item['id'];
        if (! is_string($id) || ! $this->isUuid($id)) {
            throw new AutoElevateReadException('row_drift');
        }

        // Scope proof: a row from an untrusted response must belong to the requested company.
        // Not optional — there is no caller, test or production, that may skip it.
        $rowCompany = $item['companyId'];
        if (! is_string($rowCompany) || ! $this->isUuid($rowCompany)
            || strcasecmp($rowCompany, $expectedCompanyId) !== 0) {
            throw new AutoElevateReadException('row_drift');
        }

        $machineName = $item['machineName'];
        if ($machineName !== null && ! is_string($machineName)) {
            throw new AutoElevateReadException('row_drift');
        }

        // Nested object or null — never a string.
        $os = $item['operatingSystem'];
        $osName = null;
        $osVersion = null;
        if ($os !== null) {
            if (! is_array($os) || array_is_list($os)
                || ! array_key_exists('name', $os) || ! array_key_exists('version', $os)
                || ($os['name'] !== null && ! is_string($os['name']))
                || ($os['version'] !== null && ! is_string($os['version']))) {
                throw new AutoElevateReadException('row_drift');
            }
            $osName = $os['name'];
            $osVersion = $os['version'];
        }

        $mode = $item['elevationMode'];
        if ($mode !== null && ! is_string($mode)) {
            throw new AutoElevateReadException('row_drift');
        }

        return [
            'id' => strtolower($id),
            'machine_name' => $machineName,
            'os_name' => $osName,
            'os_version' => $osVersion,
            'elevation_mode' => $mode,
            // An unrecognised non-null mode is shown verbatim and flagged, not hidden.
            'elevation_mode_known' => $mode === null || in_array($mode, self::ELEVATION_MODES, true),
            'last_checked_in_at' => self::fromEpochMs($item['lastCheckedInAt']),
        ];
    }

    /**
     * Epoch MILLISECONDS → UTC instant. The vendor's timestamps are integer ms since the
     * Unix epoch (`example: 1716900000000`); treating them as seconds lands in year 56,000
     * and an ISO parser rejects them. Null means "never reported in". Anything but int|null
     * is drift (`timestamp_drift`).
     *
     * An in-range integer is then held to a PLAUSIBILITY WINDOW, refused as
     * `timestamp_implausible`:
     *   floor   PLAUSIBLE_FLOOR_MS (2000-01-01Z) — nothing this vendor reports predates it;
     *   ceiling now + 1 year — a check-in cannot be meaningfully in the future, and a year
     *           of slack absorbs clock skew at either end without admitting nonsense.
     *
     * Measured 2026-09-18 on Carbon 3.11.1, which is why the window is a range and not a
     * mere magnitude cap: `CarbonImmutable::createFromTimestampMsUTC()` throws for NO
     * integer magnitude — PHP_INT_MAX renders as year 292278994 and 99999999999999999 as
     * year 3170843, both silently. The defect this guards is therefore always a rendered
     * absurdity, never an exception (an earlier claim that Carbon would 500 here is false).
     * The regression that will actually occur is a UNIT slip: `1716900000` (the example
     * value in SECONDS) renders as 1970-01-20 — a plausible-looking date in a "last checked
     * in" column, which a magnitude cap alone would pass. The floor catches it.
     */
    public static function fromEpochMs(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value) || $value < 0) {
            throw new AutoElevateReadException('timestamp_drift');
        }
        if ($value < self::PLAUSIBLE_FLOOR_MS || $value > CarbonImmutable::now()->addYear()->getTimestampMs()) {
            throw new AutoElevateReadException('timestamp_implausible');
        }

        return CarbonImmutable::createFromTimestampMsUTC($value);
    }

    /** Lowercase, strip everything non-alphanumeric — the match key used for auto-match. */
    public static function normalizeName(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($name)) ?? '';
    }

    /**
     * Walk every page of a list endpoint. Honours `totalCount` and the documented 200 cap:
     * asks for exactly MAX_TAKE, advances `skip` by the rows actually received, and stops
     * only when the received count reaches the vendor's latest `totalCount`. A short page
     * before that point, or more pages than MAX_PAGES, is drift — the caller gets an
     * exception, never a truncated list (C-56).
     *
     * Four ways this refuses rather than hands back a quietly wrong list:
     *   paging_over_cap        the FIRST page's `totalCount` already exceeds what this walk
     *                          can ever collect (MAX_PAGES × take = 10,000 rows). Known from
     *                          one request, so the tenant is named immediately instead of
     *                          after spending 49 more requests against a 100/hour bucket.
     *   paging_incomplete      a short page while the vendor still claims more rows.
     *   paging_bound           MAX_PAGES spent without reaching the total. Still reachable,
     *                          and still needed: `totalCount` may GROW mid-walk, so a walk
     *                          that started under the cap can run out of pages. The first-page
     *                          check cannot see that; this one can.
     *   paging_count_mismatch  the unique rows collected do not equal the vendor's latest
     *                          `totalCount`. Duplicate ids across pages are the shape this
     *                          catches: an unstably-sorted vendor can repeat a row on page N+1
     *                          and omit another, which silently DROPS a machine while the row
     *                          count still looks right. De-duplicating alone would hide that
     *                          as a short list; reconciling turns it into a failed read.
     *
     * De-duplication is by `id`, but `skip` ADVANCES BY ROWS RECEIVED, never by unique rows
     * kept: `skip` is the vendor's cursor into its own result set, and advancing it by the
     * smaller unique count would re-request rows already seen and walk the same page forever.
     * Rows whose `id` is absent or not a string are kept as-is for the per-row validators to
     * reject (`row_drift`); paging never silently discards a row it cannot key.
     *
     * @param  array<string, int|string>  $query
     * @return list<array<string, mixed>>
     */
    private function allItems(string $path, array $query): array
    {
        $items = [];
        $seenIds = [];
        $skip = 0;
        $take = AutoElevateClient::MAX_TAKE;
        $total = 0;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $result = $this->client->getPage($path, $query + ['take' => $take, 'skip' => $skip]);
            $received = count($result['items']);
            $total = $result['totalCount'];

            if ($page === 0 && $total > self::MAX_PAGES * $take) {
                throw new AutoElevateReadException('paging_over_cap');
            }

            foreach ($result['items'] as $item) {
                $id = $item['id'] ?? null;
                if (is_string($id)) {
                    $key = strtolower($id);
                    if (isset($seenIds[$key])) {
                        continue;
                    }
                    $seenIds[$key] = true;
                }
                $items[] = $item;
            }

            // The vendor's cursor, not our kept count. See the docblock.
            $skip += $received;

            if ($skip >= $total) {
                // Reached the vendor's own total (documented as 0 once `skip` is past the end).
                return $this->reconciled($items, $total);
            }

            if ($received < $take) {
                // The vendor says more rows exist but handed back a short page.
                throw new AutoElevateReadException('paging_incomplete');
            }
        }

        throw new AutoElevateReadException('paging_bound');
    }

    /**
     * The collected unique rows must account for exactly the vendor's latest `totalCount`.
     * Fewer means rows were repeated and therefore others were dropped; more means the
     * vendor handed back rows it does not admit to having. Either way the list is not the
     * tenant's machines, and a wrong list must scream rather than render (C-56).
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function reconciled(array $items, int $total): array
    {
        if (count($items) !== $total) {
            throw new AutoElevateReadException('paging_count_mismatch');
        }

        return $items;
    }

    private function isUuid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }
}
