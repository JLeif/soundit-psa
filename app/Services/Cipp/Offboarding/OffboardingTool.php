<?php

namespace App\Services\Cipp\Offboarding;

final class OffboardingTool
{
    public static function definitions(): array
    {
        $schema = [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['client_id', 'person_id', 'ticket_id', 'confirm_upn', 'reason', 'staged', 'actions'],
            'properties' => [
                'client_id' => ['type' => 'integer', 'minimum' => 1],
                'person_id' => ['type' => 'integer', 'minimum' => 1],
                'ticket_id' => ['type' => 'integer', 'minimum' => 1],
                'confirm_upn' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 320],
                'reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1000],
                'staged' => ['type' => 'boolean', 'const' => true],
                'actions' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 11, 'uniqueItems' => true,
                    'items' => ['type' => 'string', 'enum' => array_keys(OffboardingPlan::ACTIONS)]],
                'successor_person_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Required only for recipient actions; one different active same-client member.'],
                'keep_copy' => ['type' => 'boolean', 'description' => 'Required only with forward_to_successor. No default.'],
            ],
        ];

        return array_map(fn ($name) => ['name' => $name,
            'description' => 'Stage one sealed offboarding plan for explicit human approval. HELD-ONLY: staged=true required regardless of grant mode. Choose actions explicitly; no destructive keys, rerun, reset, schedule or notifications. Recipient actions require one successor; forwarding requires explicit keep_copy and excludes disable_forwarding; full-access modes are mutually exclusive. No job is submitted by staging. Default-ungranted. Admission is not execution or verified effects; progress/reconciliation requires the separate follow-up capability.',
            'inputSchema' => $schema,
        ], ['cipp_offboard_user', 'cipp_stage_offboard_user']);
    }
}
