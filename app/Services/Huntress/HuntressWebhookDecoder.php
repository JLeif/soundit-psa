<?php

namespace App\Services\Huntress;

use DateTimeImmutable;
use InvalidArgumentException;
use stdClass;

/** Decode only after raw-byte signature verification. Never retain vendor prose. */
class HuntressWebhookDecoder
{
    public function decode(string $body): ?array
    {
        $p = json_decode($body, false, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        $this->require($p instanceof stdClass && isset($p->event_type) && is_string($p->event_type));
        if (! in_array($p->event_type, ['incident_report.created', 'incident_report.closed', 'escalation.created', 'escalation.closed'], true)) {
            return null;
        }
        $incident = str_starts_with($p->event_type, 'incident_report.');
        $this->require(isset($p->account) && $p->account instanceof stdClass && property_exists($p->account, 'id'));
        $account = $this->id($p->account->id, true);
        $id = $this->id($p->id ?? null);
        $created = $this->timestamp($p->created_at ?? null);
        $anchor = $created;
        $orgs = [];
        if ($incident) {
            $this->require(isset($p->organization) && $p->organization instanceof stdClass && property_exists($p->organization, 'id'));
            $org = $this->id($p->organization->id, true);
            if ($org !== null) {
                $orgs[] = $org;
            }
            $this->require(property_exists($p, 'sent_at') && property_exists($p, 'closed_at'));
            $anchor = $p->sent_at === null ? $created : $this->timestamp($p->sent_at);
            $closed = $p->closed_at === null ? null : $this->timestamp($p->closed_at);
        } else {
            $this->require(isset($p->organizations) && is_array($p->organizations) && property_exists($p, 'resolved_at'));
            foreach ($p->organizations as $org) {
                $this->require($org instanceof stdClass && property_exists($org, 'id'));
                $orgs[] = $this->id($org->id);
            }
            $closed = $p->resolved_at === null ? null : $this->timestamp($p->resolved_at);
        }
        $orgs = array_values(array_unique($orgs));
        sort($orgs, SORT_NUMERIC);

        return [
            'event_type' => $p->event_type,
            'account_id' => $account,
            'record_type' => $incident ? 'incident_report' : 'escalation',
            'record_id' => $id,
            'organization_ids' => $orgs,
            'agent_id' => $incident ? $this->id($p->agent_id ?? null, true) : null,
            'record_created_at' => $created,
            'correlation_at' => $anchor,
            'resolved' => str_ends_with($p->event_type, '.closed') || $closed !== null,
        ];
    }

    private function id(mixed $value, bool $nullable = false): ?int
    {
        // Larger JSON integers become strings: refuse, never round or truncate.
        $this->require(($nullable && $value === null) || (is_int($value) && $value > 0));

        return $value;
    }

    private function timestamp(mixed $value): string
    {
        $this->require(is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value) === 1);
        try {
            $date = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            $this->require($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
        } catch (\Exception) {
            throw new InvalidArgumentException('Invalid webhook timestamp');
        }

        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function require(bool $valid): void
    {
        if (! $valid) {
            throw new InvalidArgumentException('Invalid webhook identity');
        }
    }
}
