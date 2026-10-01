<?php

namespace App\Services\Chet;

use App\Models\TeamsPersona;
use App\Support\TeamsBotConfig;

/**
 * The closed set of Teams conversations teams_post_message may post to: exactly
 * the conversations the existing bridge already posts to, never a caller-chosen
 * chat id (card 5sALzgSC).
 *
 *  - `operator`   — the chat post_to_operator posts to: the persona's own
 *                   conversation_refs for a persona token, else
 *                   teams_chet_conversation_id with teams_escalation_service_url
 *                   (OperatorBridgeToolExecutor::postToOperator()).
 *  - `escalation` — the shared chat EscalationNotifier posts to:
 *                   teams_escalation_conversation_id + teams_escalation_service_url.
 *                   Legacy (non-persona) tokens only; a persona bot is not known
 *                   to be a member of it.
 *
 * A caller may name a target by key or by its exact configured conversation id.
 * Anything else, and any named target whose settings are incomplete, resolves to
 * null and the caller refuses (fail closed). No fallback, no webhook.
 */
final class TeamsPostTargets
{
    /** @return array<string, array{conversation_id: ?string, service_url: ?string}> */
    public static function allowed(?TeamsPersona $persona): array
    {
        if ($persona !== null) {
            $refs = is_array($persona->conversation_refs) ? $persona->conversation_refs : [];

            return [
                'operator' => [
                    'conversation_id' => self::clean($refs['conversation_id'] ?? null),
                    'service_url' => self::clean($refs['service_url'] ?? null),
                ],
            ];
        }

        return [
            'operator' => [
                'conversation_id' => TeamsBotConfig::chetConversationId(),
                'service_url' => TeamsBotConfig::escalationServiceUrl(),
            ],
            'escalation' => [
                'conversation_id' => TeamsBotConfig::escalationConversationId(),
                'service_url' => TeamsBotConfig::escalationServiceUrl(),
            ],
        ];
    }

    /** @return array<int, string> */
    public static function keys(?TeamsPersona $persona): array
    {
        return array_keys(self::allowed($persona));
    }

    /**
     * Null means not an allowed target: the caller refuses.
     *
     * @return array{key: string, conversation_id: string, service_url: ?string}|null
     */
    public static function resolve(string $chatOrChannel, ?TeamsPersona $persona): ?array
    {
        $wanted = trim($chatOrChannel);
        if ($wanted === '') {
            return null;
        }

        foreach (self::allowed($persona) as $key => $target) {
            $id = $target['conversation_id'];
            if ($id === null) {
                continue;
            }

            if (strcasecmp($wanted, $key) === 0 || $wanted === $id) {
                return ['key' => $key, 'conversation_id' => $id, 'service_url' => $target['service_url']];
            }
        }

        return null;
    }

    private static function clean(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
