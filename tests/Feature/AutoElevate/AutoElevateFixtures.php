<?php

namespace Tests\Feature\AutoElevate;

/**
 * Fixture rows copied from the vendor's documented Computer/Company shapes
 * (AutoElevate Partner API (Beta) 1.0.0 OpenAPI, components/schemas, verified 2026-09-17)
 * and the field facts measured on production the same day: `{items,totalCount}` envelope,
 * `take` capped at 200, epoch-MILLISECOND integer timestamps, `operatingSystem` a nested
 * `{name,version}` object or null, `elevationMode` audit|live|policy|technicianBypass|null.
 * Names and ids are synthetic; the shapes are the vendor's.
 */
trait AutoElevateFixtures
{
    public const COMPANY_A = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';

    public const COMPANY_B = 'b2c3d4e5-f6a7-8901-bcde-f12345678901';

    /** 2024-05-28T12:40:00Z — the OpenAPI example value, in milliseconds. */
    public const EXAMPLE_MS = 1716900000000;

    /** @return array<string, mixed> */
    public static function company(string $id, string $name, ?string $msId = null): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'initials' => null,
            'managementSystemCompanyId' => $msId ?? $name,
            'createdAt' => self::EXAMPLE_MS,
            'updatedAt' => self::EXAMPLE_MS,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function computer(array $overrides = []): array
    {
        return array_replace([
            'id' => 'c3d4e5f6-a7b8-9012-cdef-123456789012',
            'machineName' => 'WS-FINANCE-04',
            'operatingSystem' => ['name' => 'Windows 11 Pro', 'version' => '10.0.26100'],
            'locationId' => 'd4e5f6a7-b8c9-0123-def1-234567890123',
            'companyId' => self::COMPANY_A,
            'elevationMode' => 'audit',
            'lastCheckedInAt' => self::EXAMPLE_MS,
            'createdAt' => self::EXAMPLE_MS,
            'updatedAt' => self::EXAMPLE_MS,
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{items: list<array<string, mixed>>, totalCount: int}
     */
    public static function envelope(array $items, ?int $totalCount = null): array
    {
        return ['items' => $items, 'totalCount' => $totalCount ?? count($items)];
    }

    /** Deterministic v4-looking uuid for row N. */
    public static function uuid(int $n): string
    {
        return sprintf('%08x-%04x-4%03x-8%03x-%012x', $n, $n & 0xFFFF, $n & 0xFFF, $n & 0xFFF, $n);
    }
}
