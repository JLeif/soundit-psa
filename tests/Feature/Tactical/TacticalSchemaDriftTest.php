<?php

namespace Tests\Feature\Tactical;

use Tests\TestCase;

/**
 * Pinned-contract guard, proven against the VENDOR'S OWN producer tuples.
 *
 * Two fixtures, two jobs (psa-0pb9m R2 finding d):
 *
 *  - tests/Fixtures/tactical/upstream_producers.json — field tuples captured
 *    VERBATIM from the amidaware/tacticalrmm source at a named commit by
 *    scripts/tactical-capture-upstream-producers.py (serializer Meta tuples,
 *    the calculate_agent_checks summary keys, model columns). The
 *    every-relied-on-field tests below check our EXPECTED_* lists against
 *    THESE — the vendor's own code, not a fixture we authored — which is the
 *    CLAUDE.md fixture-from-producer rule made mechanical.
 *
 *  - tests/Fixtures/tactical/api_schema.json — the trimmed human-readable
 *    OpenAPI-shaped contract (with the vendor caveats documented per schema).
 *    Kept consistent with the producer capture; SHOULD additionally be
 *    refreshed from a live /api/schema/ when an instance exists.
 *
 * This still does not auto-detect REMOTE drift at CI time (that would require
 * hitting a live Tactical); refreshing the producer capture against a new
 * upstream clone is the documented, one-command refresh path. Runtime absent-
 * key refusal complements this: the platform guard refuses payload rows whose
 * safety-critical keys (plat / shell / supported_platforms) are missing or
 * empty rather than defaulting them to "compatible".
 */
class TacticalSchemaDriftTest extends TestCase
{
    /**
     * Django models declare no explicit `id` — it is the framework's implicit
     * AutoField pk, absent from a column capture but present in fields=__all__
     * serializer output.
     */
    private const IMPLICIT_PK = 'id';

    /**
     * The policies/{pk}/related/ fields the policy-membership proof consumes
     * (TacticalCheckPlatformGuard::provePolicyMembership — psa-0pb9m R2).
     *
     * @var string[]
     */
    private const EXPECTED_POLICY_RELATED_FIELDS = [
        'agents',
        'workstation_clients',
        'server_clients',
        'workstation_sites',
        'server_sites',
        'is_default_server_policy',
        'is_default_workstation_policy',
    ];

    /**
     * The agents-list fields the membership proof resolves platforms and
     * scopes from.
     *
     * @var string[]
     */
    private const EXPECTED_MEMBERSHIP_AGENT_FIELDS = [
        'agent_id',
        'hostname',
        'plat',
        'operating_system',
        'monitoring_type',
        'client_name',
        'site_name',
    ];

    /**
     * Agent fields the daily device sync (TacticalDeviceSyncService) depends on.
     *
     * @var string[]
     */
    private const EXPECTED_AGENT_FIELDS = [
        'agent_id',
        'hostname',
        'client_name',
        'site_name',
        'logged_username',
        'operating_system',
        'plat',
        'public_ip',
        'local_ips',
        'cpu_model',
        'physical_disks',
        'graphics',
        'make_model',
        'serial_number',
        'status',
        'version',
        'last_seen',
        'needs_reboot',
        'has_patches_pending',
        'monitoring_type',
        'checks',
    ];

    /**
     * Checks summary-dict keys the coverage classifier depends on
     * (TacticalFieldMap::checksFromAgentSummary — psa-0pb9m).
     *
     * @var string[]
     */
    private const EXPECTED_CHECKS_SUMMARY_FIELDS = [
        'total',
        'passing',
        'failing',
        'warning',
        'info',
    ];

    /**
     * Per-check-result fields the checks-list surfaces depend on
     * (TacticalFieldMap::checksSummary / checkStatus, the per-check last_run
     * stamp — psa-0pb9m).
     *
     * @var string[]
     */
    private const EXPECTED_CHECK_RESULT_FIELDS = [
        'status',
        'retcode',
        'stdout',
        'last_run',
    ];

    /**
     * Script-catalog fields the platform guard depends on
     * (TacticalPlatform::scriptIncompatibility, TacticalCheckPlatformGuard —
     * psa-0pb9m).
     *
     * @var string[]
     */
    private const EXPECTED_SCRIPT_FIELDS = [
        'id',
        'name',
        'shell',
        'supported_platforms',
    ];

    /**
     * Alert fields the hourly reconciliation poll (TacticalReconcileAlerts) depends on.
     *
     * @var string[]
     */
    private const EXPECTED_ALERT_FIELDS = [
        'id',
        'resolved',
    ];

    private const REFRESH_HINT = 'Refresh the pinned snapshot from a live instance: enable SWAGGER_ENABLED in Tactical, '
        .'then `curl -s https://<tactical-host>/api/schema/ > tests/Fixtures/tactical/api_schema.json` and re-trim. See INSTALL.md §9.';

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $path = base_path('tests/Fixtures/tactical/api_schema.json');
        $this->assertFileExists($path, 'Pinned Tactical schema snapshot is missing.');

        return json_decode(file_get_contents($path), true);
    }

    /**
     * @return string[]
     */
    private function propertyNames(array $schema, string $component): array
    {
        $props = $schema['components']['schemas'][$component]['properties'] ?? null;
        $this->assertIsArray(
            $props,
            "Pinned snapshot is missing the `{$component}` schema component. ".self::REFRESH_HINT,
        );

        return array_keys($props);
    }

    public function test_agent_schema_contains_every_field_we_depend_on(): void
    {
        $present = $this->propertyNames($this->schema(), 'AgentTable');

        foreach (self::EXPECTED_AGENT_FIELDS as $field) {
            $this->assertContains(
                $field,
                $present,
                "Tactical agent schema no longer exposes `{$field}` (used by TacticalDeviceSyncService). ".self::REFRESH_HINT,
            );
        }
    }

    public function test_alert_schema_contains_every_field_we_depend_on(): void
    {
        $present = $this->propertyNames($this->schema(), 'Alert');

        foreach (self::EXPECTED_ALERT_FIELDS as $field) {
            $this->assertContains(
                $field,
                $present,
                "Tactical alert schema no longer exposes `{$field}` (used by TacticalReconcileAlerts). ".self::REFRESH_HINT,
            );
        }
    }

    public function test_checks_summary_schema_contains_every_field_the_coverage_classifier_depends_on(): void
    {
        $present = $this->propertyNames($this->schema(), 'AgentChecksSummary');

        foreach (self::EXPECTED_CHECKS_SUMMARY_FIELDS as $field) {
            $this->assertContains(
                $field,
                $present,
                "Tactical agent checks summary no longer exposes `{$field}` (used by TacticalFieldMap::checksFromAgentSummary — coverage truth would silently weaken). ".self::REFRESH_HINT,
            );
        }
    }

    public function test_check_result_schema_contains_every_field_the_check_reads_depend_on(): void
    {
        $present = $this->propertyNames($this->schema(), 'CheckResult');

        foreach (self::EXPECTED_CHECK_RESULT_FIELDS as $field) {
            $this->assertContains(
                $field,
                $present,
                "Tactical check result no longer exposes `{$field}` (used by TacticalFieldMap::checksSummary and the per-check reads). ".self::REFRESH_HINT,
            );
        }

        $status = $this->schema()['components']['schemas']['CheckResult']['properties']['status'] ?? [];
        $this->assertSame(
            ['passing', 'failing', 'pending'],
            $status['enum'] ?? null,
            'The pinned CheckResult status vocabulary changed — TacticalFieldMap::checksSummary buckets by these exact values. '.self::REFRESH_HINT,
        );
    }

    public function test_script_schema_contains_every_field_the_platform_guard_depends_on(): void
    {
        $present = $this->propertyNames($this->schema(), 'Script');

        foreach (self::EXPECTED_SCRIPT_FIELDS as $field) {
            $this->assertContains(
                $field,
                $present,
                "Tactical script schema no longer exposes `{$field}` (used by TacticalCheckPlatformGuard / TacticalPlatform — losing it silently weakens the wrong-platform guard). ".self::REFRESH_HINT,
            );
        }
    }

    public function test_snapshot_records_its_pinned_version_and_provenance(): void
    {
        $meta = $this->schema()['_meta'] ?? [];

        $this->assertArrayHasKey('pinned_tactical_version', $meta, 'Pinned snapshot must record the Tactical version it reflects.');
        $this->assertNotEmpty($meta['pinned_tactical_version']);
        $this->assertArrayHasKey('refresh_command', $meta, 'Pinned snapshot must document how to refresh it.');
    }

    // ── vendor-producer proof (psa-0pb9m R2): our reliance vs THEIR tuples ──

    /**
     * @return array<string, mixed>
     */
    private function producers(): array
    {
        $path = base_path('tests/Fixtures/tactical/upstream_producers.json');
        $this->assertFileExists($path, 'Captured upstream producer tuples are missing — run scripts/tactical-capture-upstream-producers.py against a tacticalrmm clone.');

        return json_decode(file_get_contents($path), true);
    }

    /**
     * @return string[]
     */
    private function producerFields(string $key): array
    {
        $entry = $this->producers()[$key] ?? null;
        $this->assertIsArray($entry, "upstream_producers.json is missing `{$key}` — re-run the capture script.");
        $this->assertIsArray($entry['fields'] ?? null);

        return $entry['fields'];
    }

    public function test_producer_capture_is_pinned_to_a_named_upstream_commit(): void
    {
        $meta = $this->producers()['_meta'] ?? [];

        $this->assertSame('https://github.com/amidaware/tacticalrmm', $meta['upstream_repo'] ?? null);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{40}$/',
            (string) ($meta['upstream_commit'] ?? ''),
            'The producer capture must pin the exact upstream commit it was read from.',
        );
        $this->assertArrayHasKey('refresh_command', $meta);
    }

    public function test_every_agent_field_we_rely_on_exists_in_the_vendors_list_serializer(): void
    {
        $vendor = $this->producerFields('agent_table_serializer_fields');

        foreach (self::EXPECTED_AGENT_FIELDS as $field) {
            $this->assertContains(
                $field,
                $vendor,
                "`{$field}` is not in the vendor's AgentTableSerializer Meta.fields — the device sync would silently lose it. Re-verify against upstream and refresh both fixtures.",
            );
        }
    }

    public function test_every_summary_key_we_rely_on_exists_in_the_vendors_calculate_agent_checks(): void
    {
        $vendor = $this->producerFields('agent_checks_summary_keys');

        foreach (self::EXPECTED_CHECKS_SUMMARY_FIELDS as $field) {
            $this->assertContains($field, $vendor, "`{$field}` is not a key of the vendor's calculate_agent_checks summary dict.");
        }
    }

    public function test_every_check_result_field_we_rely_on_exists_on_the_vendors_model(): void
    {
        $vendor = array_merge($this->producerFields('check_result_model_columns'), [self::IMPLICIT_PK]);

        foreach (self::EXPECTED_CHECK_RESULT_FIELDS as $field) {
            $this->assertContains($field, $vendor, "`{$field}` is not a column of the vendor's CheckResult model (serialized via fields=__all__).");
        }
    }

    public function test_every_script_field_we_rely_on_exists_on_the_vendors_model(): void
    {
        $vendor = array_merge($this->producerFields('script_model_columns'), [self::IMPLICIT_PK]);

        foreach (self::EXPECTED_SCRIPT_FIELDS as $field) {
            $this->assertContains($field, $vendor, "`{$field}` is not a column of the vendor's Script model — the platform guard would silently weaken.");
        }
    }

    public function test_policy_membership_proof_fields_exist_in_the_vendors_producers(): void
    {
        $related = $this->producerFields('policy_related_serializer_fields');
        foreach (self::EXPECTED_POLICY_RELATED_FIELDS as $field) {
            $this->assertContains(
                $field,
                $related,
                "`{$field}` is not in the vendor's PolicyRelatedSerializer Meta.fields — the policy membership proof (TacticalCheckPlatformGuard) would silently weaken.",
            );
        }

        $hostnameRows = $this->producerFields('agent_hostname_serializer_fields');
        foreach (['agent_id', 'hostname'] as $field) {
            $this->assertContains($field, $hostnameRows, "`{$field}` is not in the vendor's AgentHostnameSerializer fields (the related-agents rows).");
        }

        $fleet = $this->producerFields('agent_table_serializer_fields');
        foreach (self::EXPECTED_MEMBERSHIP_AGENT_FIELDS as $field) {
            $this->assertContains($field, $fleet, "`{$field}` is not in the vendor's agents-list serializer — membership platform resolution would silently weaken.");
        }
    }
}
