<?php

namespace Tests\Fixtures;

/** Synthetic only: no value here is a usable credential. */
class ScannerCoverage
{
    public const SEED = 'scanner-3089-v1';

    public static function randomBase64(int $count = 100000): iterable
    {
        for ($i = 0; $i < $count; $i++) {
            // Thirty deterministic bytes produce forty unpadded base64 characters.
            yield $i => base64_encode(substr(hash('sha256', self::SEED.':'.$i, true), 0, 30));
        }
    }

    public static function urls(): array
    {
        return [
            'github-root' => 'https://github.com/sounditsolutions/soundit-psa',
            'github-issues' => 'https://github.com/sounditsolutions/soundit-psa/issues',
            'github-issue' => 'https://github.com/sounditsolutions/soundit-psa/issues/3089',
            'github-blob' => 'https://github.com/sounditsolutions/soundit-psa/blob/main/README.md',
            'graph' => 'https://learn.microsoft.com/en-us/graph/api/user-list',
            'notes' => 'https://psa.example.test/tickets/22846/notes',
            'settings' => 'https://psa.example.test/admin/settings/mcp-tokens/17/edit',
            'knowledgebase' => 'https://vendor.example.test/knowledgebase/articles/738618-monitor-goes-blank',
        ];
    }

    public static function signedUrls(): array
    {
        $rows = [];
        // Deliberately include no-case-mix paths; webhooks are not necessarily base64.
        foreach (['mixed' => 'SyntheticAbCd0123456789EfGhIjKlMnOp', 'lower' => str_repeat('abcdef01', 4), 'upper' => str_repeat('ABCDEF01', 4)] as $kind => $value) {
            $rows['slack-'.$kind] = 'https://hooks.slack.com/services/T00000000/B00000000/'.$value;
            $rows['teams-'.$kind] = 'https://example.webhook.office.com/webhookb2/00000000-0000-4000-8000-000000000000/IncomingWebhook/'.$value.'/00000000-0000-4000-8000-000000000000';
            $rows['discord-'.$kind] = 'https://discord.com/api/webhooks/123456789012345678/'.$value;
            $rows['sas-'.$kind] = 'https://storage.example.test/container/blob?sv=2025-01-01&sig='.$value.'%2Fsynthetic';
            $rows['presigned-'.$kind] = 'https://bucket.example.test/object?X-Amz-Signature='.$value;
        }

        return $rows;
    }
}
