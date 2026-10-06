<?php

namespace App\Services\Mcp;

/**
 * The drafter keys every staff MCP staging site writes into a TechnicianRun's
 * proposed_meta (card tY39CHiq, after XUiMXNEH):
 *
 *  - drafted_by: the PREFIXED audit label (McpStaffToken::actorLabel()), unchanged.
 *  - drafted_by_token: the caller's BARE McpToken.label. withdraw_staged_action and
 *    TechnicianRun::drafterDisplayName() read this key only; nothing parses the
 *    prefixed drafted_by back into a label.
 *
 * When no bare label is available (a legacy token, or a run staged by a logged-in
 * user rather than a token), drafted_by_token is left OUT rather than invented, so
 * no token can withdraw that run.
 *
 * Use it by spreading into the meta array literal:
 *     $meta = [...DraftedByToken::meta($actorLabel, $tokenLabel), 'reasons' => ...];
 */
final class DraftedByToken
{
    /** @return array{drafted_by: string, drafted_by_token?: string} */
    public static function meta(string $actorLabel, ?string $tokenLabel): array
    {
        $meta = ['drafted_by' => $actorLabel];
        if ($tokenLabel !== null && $tokenLabel !== '') {
            $meta['drafted_by_token'] = $tokenLabel;
        }

        return $meta;
    }
}
