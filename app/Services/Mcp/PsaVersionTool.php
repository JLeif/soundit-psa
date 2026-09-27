<?php

namespace App\Services\Mcp;

use App\Services\VersionService;

/**
 * psa_version — which commit this instance is serving (#3982).
 *
 * A thin reshaping of VersionService::current(); the read itself lives there and
 * is not duplicated here. Nothing in this class caches: every execute() calls
 * current(), and current() reads the git plumbing files on every call.
 */
final class PsaVersionTool
{
    public const NAME = 'psa_version';

    public function __construct(private readonly VersionService $version) {}

    /** @return array<string, mixed> */
    public static function definition(): array
    {
        return [
            'name' => self::NAME,
            'description' => 'READ-ONLY. The commit this PSA instance is serving, read fresh from its git checkout on every call (never cached). Returns commit (full 40-hex sha), commit_short, read_at (when this answer was read, NOT when anything was deployed), source, and error. When the read fails, commit and commit_short are null and error says why; a null commit means "could not read", never "no commit". Takes no arguments. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => (object) [],
                'required' => [],
            ],
        ];
    }

    /**
     * @return array{commit: ?string, commit_short: ?string, read_at: ?string, source: ?string, error: ?string}
     */
    public function execute(): array
    {
        try {
            $current = $this->version->current();
        } catch (\Throwable $e) {
            return $this->failed(null, null, 'version read threw: '.$e->getMessage());
        }

        $readAt = isset($current['read_at']) ? (string) $current['read_at'] : null;
        $source = isset($current['source']) ? (string) $current['source'] : null;

        $error = $current['error'] ?? null;
        if ($error !== null && $error !== '') {
            return $this->failed($readAt, $source, (string) $error);
        }

        // current() reported no error. Its sha is still checked rather than trusted,
        // so an empty string or the UNKNOWN sentinel can never leave here as a commit.
        $sha = $current['commit_hash'] ?? null;
        if (! is_string($sha) || preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            return $this->failed($readAt, $source, 'the version read reported no error but returned no 40-hex commit id');
        }

        return [
            'commit' => $sha,
            'commit_short' => substr($sha, 0, 7),
            'read_at' => $readAt,
            'source' => $source,
            'error' => null,
        ];
    }

    /** @return array{commit: null, commit_short: null, read_at: ?string, source: ?string, error: string} */
    private function failed(?string $readAt, ?string $source, string $error): array
    {
        return [
            'commit' => null,
            'commit_short' => null,
            'read_at' => $readAt,
            'source' => $source,
            'error' => $error,
        ];
    }
}
