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
    public function normalizeComputer(array $item, ?string $expectedCompanyId = null): array
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
        $rowCompany = $item['companyId'];
        if (! is_string($rowCompany) || ! $this->isUuid($rowCompany)
            || ($expectedCompanyId !== null && strcasecmp($rowCompany, $expectedCompanyId) !== 0)) {
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
     * is drift.
     */
    public static function fromEpochMs(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value) || $value < 0) {
            throw new AutoElevateReadException('timestamp_drift');
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
     * @param  array<string, int|string>  $query
     * @return list<array<string, mixed>>
     */
    private function allItems(string $path, array $query): array
    {
        $items = [];
        $skip = 0;
        $take = AutoElevateClient::MAX_TAKE;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $result = $this->client->getPage($path, $query + ['take' => $take, 'skip' => $skip]);
            $received = count($result['items']);
            $total = $result['totalCount'];

            foreach ($result['items'] as $item) {
                $items[] = $item;
            }
            $skip += $received;

            if ($skip >= $total) {
                // Reached the vendor's own total (documented as 0 once `skip` is past the end).
                return $items;
            }

            if ($received < $take) {
                // The vendor says more rows exist but handed back a short page.
                throw new AutoElevateReadException('paging_incomplete');
            }
        }

        throw new AutoElevateReadException('paging_bound');
    }

    private function isUuid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }
}
