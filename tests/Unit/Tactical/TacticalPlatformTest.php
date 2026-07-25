<?php

namespace Tests\Unit\Tactical;

use App\Services\Tactical\TacticalPlatform;
use PHPUnit\Framework\TestCase;

/**
 * psa-0pb9m: platform resolution + script/platform compatibility probe.
 *
 * Tactical macOS/Linux agents only run SCRIPT checks, so a script whose shell
 * or supported_platforms cannot execute on the agent's platform is a check
 * that fails on 100% of runs — the "one Tactical check on every Mac fails on
 * all of them" defect class. These matrices lock the conservative rules:
 * authoritative vendor metadata (supported_platforms) wins in both directions,
 * shell heuristics only where the shell is definitively platform-bound, and
 * unknown platform/shell never produces a claim.
 */
class TacticalPlatformTest extends TestCase
{
    public function test_from_agent_payload_prefers_explicit_plat(): void
    {
        $this->assertSame('darwin', TacticalPlatform::fromAgentPayload('darwin', 'Windows 11 Pro'));
        $this->assertSame('windows', TacticalPlatform::fromAgentPayload('windows', 'Darwin 23.6.0'));
        $this->assertSame('linux', TacticalPlatform::fromAgentPayload('linux', null));
        // Normalized: case + surrounding whitespace.
        $this->assertSame('darwin', TacticalPlatform::fromAgentPayload(' Darwin ', null));
    }

    public function test_from_agent_payload_sniffs_os_string_when_plat_absent_or_unknown(): void
    {
        $this->assertSame('darwin', TacticalPlatform::fromAgentPayload(null, 'Darwin 23.6.0 arm64 23.6.0'));
        $this->assertSame('darwin', TacticalPlatform::fromAgentPayload(null, 'macOS 14.5 (Sonoma)'));
        $this->assertSame('darwin', TacticalPlatform::fromAgentPayload('freebsd', 'Mac OS X 10.15'));
        $this->assertSame('windows', TacticalPlatform::fromAgentPayload(null, 'Windows 11 Pro, 64 bit v23H2'));
        $this->assertSame('linux', TacticalPlatform::fromAgentPayload(null, 'Ubuntu 22.04.4 LTS x86_64'));
        $this->assertSame('linux', TacticalPlatform::fromAgentPayload(null, 'Debian GNU/Linux 12 (bookworm)'));
        $this->assertSame('linux', TacticalPlatform::fromAgentPayload(null, 'Rocky Linux 9.3 x86_64'));
    }

    public function test_from_agent_payload_unknown_yields_null_never_a_guess(): void
    {
        $this->assertNull(TacticalPlatform::fromAgentPayload(null, null));
        $this->assertNull(TacticalPlatform::fromAgentPayload('', ''));
        $this->assertNull(TacticalPlatform::fromAgentPayload(null, 'TempleOS 5.03'));
    }

    public function test_supported_platforms_metadata_is_authoritative_when_present(): void
    {
        // Windows-only script on a Mac: the vendor's own metadata says it cannot run.
        $reason = TacticalPlatform::scriptIncompatibility('darwin', 'powershell', ['windows']);
        $this->assertIsString($reason);
        $this->assertStringContainsString('windows', $reason);

        // Metadata that INCLUDES the platform clears even a powershell shell —
        // the operator has declared pwsh present (vendor metadata wins both ways).
        $this->assertNull(TacticalPlatform::scriptIncompatibility('darwin', 'powershell', ['darwin', 'linux']));

        // Case-insensitive on both sides.
        $this->assertNull(TacticalPlatform::scriptIncompatibility('darwin', 'shell', ['Darwin']));
    }

    public function test_shell_heuristics_apply_only_without_metadata(): void
    {
        // cmd is Windows-only, full stop.
        $this->assertIsString(TacticalPlatform::scriptIncompatibility('darwin', 'cmd', []));
        $this->assertIsString(TacticalPlatform::scriptIncompatibility('linux', 'cmd', null));
        $this->assertNull(TacticalPlatform::scriptIncompatibility('windows', 'cmd', []));

        // powershell on a non-Windows box needs pwsh, which is rarely present —
        // flagged (likely-incompatible) when no metadata vouches for it.
        $this->assertIsString(TacticalPlatform::scriptIncompatibility('darwin', 'powershell', []));
        $this->assertIsString(TacticalPlatform::scriptIncompatibility('linux', 'powershell', null));
        $this->assertNull(TacticalPlatform::scriptIncompatibility('windows', 'powershell', []));

        // Cross-platform shells are never flagged by heuristic alone.
        $this->assertNull(TacticalPlatform::scriptIncompatibility('darwin', 'shell', []));
        $this->assertNull(TacticalPlatform::scriptIncompatibility('darwin', 'python', []));
        $this->assertNull(TacticalPlatform::scriptIncompatibility('darwin', 'nushell', []));
    }

    public function test_unknown_platform_or_shell_never_claims_incompatibility(): void
    {
        $this->assertNull(TacticalPlatform::scriptIncompatibility(null, 'cmd', ['windows']));
        $this->assertNull(TacticalPlatform::scriptIncompatibility('darwin', null, []));
        $this->assertNull(TacticalPlatform::scriptIncompatibility('darwin', '', null));
    }
}
