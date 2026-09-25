<?php

namespace App\Services\Portal;

use App\Models\Client;
use App\Services\Level\LevelClient;
use App\Services\Ninja\NinjaClient;
use App\Services\Tactical\TacticalClient;
use App\Support\PortalConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PortalInstallService
{
    /** Web Generate deliberately refuses any existing token, including an expired one. */
    public function generateInstallLink(Client $client): array
    {
        if ($client->portal_install_token) {
            return ['error' => 'This client already has an install link. Use Rotate to replace it.'];
        }

        if (empty($client->availableRmms())) {
            return ['error' => 'Map this client to an RMM (Ninja, Level, or Tactical) before generating an install link.'];
        }

        $available = $client->availableRmms();
        $client->update([
            'portal_install_token' => Str::random(32),
            'portal_install_token_expires_at' => now()->addDays(PortalConfig::installTokenTtlDays()),
            'portal_primary_rmm' => count($available) === 1 ? $available[0] : $client->portal_primary_rmm,
        ]);

        return ['success' => 'Install link generated.'];
    }

    public function rotateInstallLink(Client $client): array
    {
        if (! $client->portal_install_token) {
            return ['error' => 'No install link to rotate.'];
        }

        $client->update([
            'portal_install_token' => Str::random(32),
            'portal_install_token_expires_at' => now()->addDays(PortalConfig::installTokenTtlDays()),
        ]);

        return ['success' => 'Install link rotated. The previous URL is no longer valid.'];
    }

    public function disableInstallLink(Client $client): array
    {
        $client->update([
            'portal_install_token' => null,
            'portal_install_token_expires_at' => null,
            'portal_primary_rmm' => null,
        ]);

        return ['success' => 'Install link disabled.'];
    }

    /** Staff MCP differs from web Generate: reuse live links and replace expired ones. */
    public function getOrCreateInstallLink(Client $client): array
    {
        return DB::transaction(function () use ($client): array {
            $client = Client::query()->lockForUpdate()->findOrFail($client->id);
            $context = [
                'available_rmms' => $client->availableRmms(),
                'effective_rmm' => $client->effectiveInstallRmm(),
                'portal_primary_rmm' => $client->portal_primary_rmm,
            ];
            // Match Client::operational() on the locked row, including live-link retrieval.
            if (! $client->is_active || $client->stage !== \App\Enums\ClientStage::Active) {
                return ['error' => 'Install links are unavailable for non-operational clients.'] + $context;
            }
            if (empty($context['available_rmms'])) {
                return ['error' => 'Map this client to an RMM (Ninja, Level, or Tactical) on the Client page before generating an install link.'] + $context;
            }
            if ($context['effective_rmm'] === null) {
                return ['error' => 'Set the primary RMM on the Client page before generating an install link.'] + $context;
            }

            $publicRoot = config('app.url');
            // parse_url rewrites raw controls to underscores; refuse before parsing.
            if (! is_string($publicRoot) || preg_match('/[^\x21-\x7E]/', $publicRoot)) {
                return ['error' => 'Configure a public HTTP(S) application URL before requesting an install link.'] + $context;
            }
            $parts = parse_url($publicRoot);
            $scheme = strtolower($parts['scheme'] ?? '');
            $host = $parts['host'] ?? '';
            $bracketed = str_starts_with($host, '[') && str_ends_with($host, ']');
            $labels = explode('.', $host);
            $lastLabel = end($labels);
            $numericHost = ctype_digit($lastLabel) || str_starts_with(strtolower($lastLabel), '0x');
            // This is a link a client will click: never hand out a host WHATWG would
            // reject or rewrite into a different address. Numeric hosts must be strict IPv4.
            $validHost = $bracketed
                ? filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                : (! str_contains($host, ':') && ! str_ends_with($host, '.') && ($numericHost
                    ? filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                    : filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false));
            $path = $parts['path'] ?? '';
            // A single terminal slash is a root separator, not a prefix segment.
            $prefix = str_ends_with($path, '/') ? substr($path, 0, -1) : $path;
            $segments = $prefix === '' ? [] : explode('/', substr($prefix, 1));
            $normalized = true;
            foreach ($segments as $segment) {
                $decoded = rawurldecode($segment);
                if ($decoded === '' || $decoded === '.' || $decoded === '..'
                    || str_contains($decoded, '/') || str_contains($decoded, '\\')
                    || ! preg_match('/\A(?:[A-Za-z0-9._~!$&\x27()*+,;=:@-]|%[0-9A-Fa-f]{2})+\z/', $segment)) {
                    $normalized = false;
                }
            }
            if (! is_array($parts) || ! in_array($scheme, ['http', 'https'], true)
                || ! $validHost
                || (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535))
                || str_contains($publicRoot, '\\') || ! $normalized
                || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment'])) {
                return ['error' => 'Configure a public HTTP(S) application URL before requesting an install link.'] + $context;
            }

            $expired = (bool) $client->portal_install_token
                && $client->portal_install_token_expires_at !== null
                && $client->portal_install_token_expires_at->isPast();
            if ($expired) {
                $this->rotateInstallLink($client);
            } elseif (! $client->portal_install_token) {
                $this->generateInstallLink($client);
            }

            return [
                'url' => $scheme.'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '').$prefix.route('portal.install.show', ['token' => $client->portal_install_token], false),
                'expires_at' => $client->portal_install_token_expires_at?->toIso8601String(),
                'portal_primary_rmm' => $client->portal_primary_rmm,
                'reissued_expired' => $expired,
            ] + $context;
        });
    }

    /**
     * Platforms we check against each RMM. Each RMM reports availability
     * per platform; unsupported platforms are dropped from the page.
     */
    private const PLATFORMS = ['windows', 'mac', 'linux'];

    /**
     * Look up a client by install token. Returns null if the token doesn't
     * resolve — or resolved but has expired (#864). An expired token is
     * indistinguishable from an invalid one on purpose: answering
     * differently would let an unauthenticated scanner distinguish a real
     * client link from a guess. A NULL expiry means a deliberate per-row
     * "no expiry" exception and stays valid.
     */
    public function findByToken(string $token): ?Client
    {
        if (empty($token)) {
            return null;
        }

        $client = Client::where('portal_install_token', $token)->first();
        if (! $client) {
            return null;
        }

        $expiresAt = $client->portal_install_token_expires_at;
        if ($expiresAt !== null && $expiresAt->isPast()) {
            Log::info('[PortalInstall] Expired token presented', [
                'client_id' => $client->id,
                'expired_at' => $expiresAt->toIso8601String(),
            ]);

            return null;
        }

        return $client;
    }

    /**
     * Platforms the client's RMM can install on, WITHOUT minting anything
     * (#857): availability is answered from configuration and read-only
     * lookups only. Empty array means no usable RMM mapping.
     *
     * @return array<int, string>
     */
    public function supportedPlatforms(Client $client): array
    {
        $rmm = $client->effectiveInstallRmm();
        if (! $rmm) {
            return [];
        }

        $platforms = [];
        foreach (self::PLATFORMS as $platform) {
            if ($this->platformSupported($client, $rmm, $platform)) {
                $platforms[] = $platform;
            }
        }

        return $platforms;
    }

    /**
     * Resolve the installer for ONE platform. For Tactical this mints a live
     * enrolment credential upstream, so callers are the mint points: the
     * explicit "show my install command" POST and the signed download
     * redirect — never a bare page GET.
     */
    public function buildInstaller(Client $client, string $platform, string $goarch = 'amd64'): ?InstallerInfo
    {
        $rmm = $client->effectiveInstallRmm();
        if (! $rmm || ! in_array($platform, self::PLATFORMS, true)) {
            return null;
        }

        return $this->resolveInstaller($client, $rmm, $platform, $goarch);
    }

    /**
     * Assemble the page model: branding plus the platform map. Values are
     * InstallerInfo for a platform whose credential has been minted this
     * request, null for a platform that is available but unminted.
     *
     * @param  array<string, InstallerInfo|null>  $platforms
     */
    public function package(Client $client, array $platforms): InstallerPackage
    {
        return new InstallerPackage(
            clientName: $client->name,
            rmmLabel: $this->rmmLabel($client->effectiveInstallRmm() ?? ''),
            platforms: $platforms,
            mspName: PortalConfig::companyName(),
            mspLogoUrl: PortalConfig::logoUrl(),
            supportEmail: PortalConfig::supportEmail(),
            supportPhone: PortalConfig::supportPhone(),
        );
    }

    private function platformSupported(Client $client, string $rmm, string $platform): bool
    {
        try {
            return match ($rmm) {
                'level' => app(LevelClient::class)->supportsInstall((string) $client->level_group_id, $platform),
                'ninja' => app(NinjaClient::class)->supportsInstall((int) $client->ninja_org_id, $platform),
                'tactical' => \App\Support\TacticalConfig::isEnabled() && app(TacticalClient::class)->supportsInstall((string) $client->tactical_site_id, $platform),
                default => false,
            };
        } catch (\Throwable $e) {
            Log::warning('[PortalInstall] RMM availability lookup failed', [
                'client_id' => $client->id,
                'rmm' => $rmm,
                'platform' => $platform,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function resolveInstaller(Client $client, string $rmm, string $platform, string $goarch): ?InstallerInfo
    {
        try {
            return match ($rmm) {
                'level' => app(LevelClient::class)->getInstallerInfo((string) $client->level_group_id, $platform),
                'ninja' => app(NinjaClient::class)->getInstallerInfo((int) $client->ninja_org_id, $platform),
                'tactical' => \App\Support\TacticalConfig::isEnabled()
                    ? app(TacticalClient::class)->getInstallerInfo((string) $client->tactical_site_id, $platform, $goarch)
                    : null,
                default => null,
            };
        } catch (\Throwable $e) {
            Log::warning('[PortalInstall] RMM installer lookup failed', [
                'client_id' => $client->id,
                'rmm' => $rmm,
                'platform' => $platform,
                'error_type' => get_class($e),
            ]);

            return null;
        }
    }

    private function rmmLabel(string $rmm): string
    {
        return match ($rmm) {
            'ninja' => 'NinjaRMM Agent',
            'level' => 'Level Agent',
            'tactical' => 'Tactical RMM Agent',
            default => 'Management Agent',
        };
    }
}
