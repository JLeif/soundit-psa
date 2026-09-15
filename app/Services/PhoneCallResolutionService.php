<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\PhoneCallResolutionProposal;
use App\Models\TechnicianActionLog;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Single-call identity resolution. Never links tickets, bills time, or invokes vendors. */
class PhoneCallResolutionService
{
    public function execute(array $arguments, int $clientId, string $actor, bool $staged): array
    {
        if (TechnicianConfig::killSwitchEngaged()) {
            return ['error' => 'Technician kill-switch engaged.'];
        }
        if (array_diff(array_keys($arguments), ['phone_call_id', 'contact_id', 'reason']) !== []
            || ! is_int($arguments['phone_call_id'] ?? null) || $arguments['phone_call_id'] < 1
            || ! is_int($arguments['contact_id'] ?? null) || $arguments['contact_id'] < 1
            || $clientId < 1 || ! is_string($arguments['reason'] ?? null)
            || trim($arguments['reason']) === '' || mb_strlen($arguments['reason']) > 800) {
            return ['error' => 'Positive integer phone_call_id, client_id, contact_id and a reason (1–800 characters) are required; no other arguments.'];
        }
        $payload = ['phone_call_id' => $arguments['phone_call_id'], 'client_id' => $clientId,
            'contact_id' => $arguments['contact_id'], 'reason' => trim($arguments['reason'])];

        return DB::transaction(function () use ($payload, $actor, $staged): array {
            try {
                $call = $this->validatedCall($payload);
            } catch (\DomainException $e) {
                return ['error' => $e->getMessage()];
            }
            if (! $staged) {
                return $this->apply($call, $payload, $actor);
            }
            $payload['snapshot'] = $this->snapshot($call);
            $hash = $this->hash($payload);
            // Call lock serializes proposals and execution for this single target.
            $proposal = PhoneCallResolutionProposal::where('content_hash', $hash)->where('state', 'pending')
                ->where('drafted_by', $actor)->first();
            if (! $proposal) {
                $proposal = PhoneCallResolutionProposal::create([
                    'phone_call_id' => $call->id, 'client_id' => $payload['client_id'],
                    'payload' => $payload, 'content_hash' => $hash, 'state' => 'pending', 'drafted_by' => $actor,
                ]);
                $this->audit($call, $payload, $actor, 'awaiting_approval');
            }

            return ['success' => true, 'staged' => true, 'proposal_id' => $proposal->id,
                'phone_call_id' => $call->id, 'client_id' => $call->client_id, 'person_id' => $call->person_id,
                'ticket_id' => $call->ticket_id, 'message' => 'Caller identity held for cockpit approval; no identity changed.'];
        });
    }

    public function approve(int $id, User $approver): array
    {
        if (! $this->canApprove($approver)) {
            return ['error' => 'Active administrator or technician permission required.'];
        }
        if (TechnicianConfig::killSwitchEngaged()) {
            return ['error' => 'Technician kill-switch engaged.'];
        }

        return DB::transaction(function () use ($id, $approver): array {
            $proposal = PhoneCallResolutionProposal::whereKey($id)->lockForUpdate()->first();
            if (! $proposal || $proposal->state !== 'pending') {
                return ['error' => 'Proposal already handled or not found.'];
            }
            try {
                $payload = $proposal->payload;
                if (! is_array($payload) || ! hash_equals($proposal->content_hash, $this->hash($payload))
                    || ($payload['phone_call_id'] ?? null) !== $proposal->phone_call_id
                    || ($payload['client_id'] ?? null) !== $proposal->client_id) {
                    throw new \DomainException('Proposal binding changed; re-stage.');
                }
                $call = $this->validatedCall($payload);
                if (($payload['snapshot'] ?? null) !== $this->snapshot($call)) {
                    throw new \DomainException('Call identity or ticket association changed; re-stage.');
                }
            } catch (\DomainException $e) {
                $proposal->update(['state' => 'stale', 'handled_at' => now(), 'approved_by' => $approver->id]);

                return ['error' => $e->getMessage()];
            }
            $result = $this->apply($call, $payload, $proposal->drafted_by, $approver->id);
            $proposal->update(['state' => 'done', 'handled_at' => now(), 'approved_by' => $approver->id]);

            return $result + ['proposal_id' => $proposal->id];
        });
    }

    public function deny(int $id, User $approver): array
    {
        if (! $this->canApprove($approver)) {
            return ['error' => 'Active administrator or technician permission required.'];
        }
        $changed = PhoneCallResolutionProposal::whereKey($id)->where('state', 'pending')->update([
            'state' => 'denied', 'handled_at' => now(), 'approved_by' => $approver->id,
        ]);

        return $changed ? ['success' => true, 'message' => 'Caller resolution denied.'] : ['error' => 'Proposal already handled or not found.'];
    }

    public function canApprove(User $user): bool
    {
        $current = User::find($user->id);

        return $current && $current->is_active && ($current->isAdmin() || $current->isTech());
    }

    private function validatedCall(array $payload): PhoneCall
    {
        $call = PhoneCall::whereKey($payload['phone_call_id'])->lockForUpdate()->first();
        $client = Client::whereKey($payload['client_id'])->lockForUpdate()->first();
        $person = Person::whereKey($payload['contact_id'])->lockForUpdate()->first();
        if (! $call || ! $client || ! $person || ! $client->is_active || ! $person->is_active
            || $person->client_id !== $client->id) {
            throw new \DomainException('Call or active client/contact not found, or contact ownership mismatch.');
        }
        if ($call->ticket_id !== null) {
            $ticket = Ticket::whereKey($call->ticket_id)->lockForUpdate()->first();
            if (! $ticket || $ticket->client_id !== $client->id) {
                throw new \DomainException('Linked ticket missing or ticket-client mismatch.');
            }
        }
        $identical = $call->client_id === $client->id && $call->person_id === $person->id;
        if (! $identical && ($call->person_confirmed
            || ($call->client_id !== null && $call->client_id !== $client->id)
            || ($call->person_id !== null && $call->person_id !== $person->id))) {
            throw new \DomainException('Refusing to replace a different resolved or confirmed caller identity.');
        }

        return $call;
    }

    private function snapshot(PhoneCall $call): array
    {
        return ['client_id' => $call->client_id, 'person_id' => $call->person_id,
            'person_confirmed' => (bool) $call->person_confirmed, 'ticket_id' => $call->ticket_id,
            'updated_at' => $call->getRawOriginal('updated_at')];
    }

    private function apply(PhoneCall $call, array $payload, string $actor, ?int $approver = null): array
    {
        $unchanged = $call->client_id === $payload['client_id'] && $call->person_id === $payload['contact_id'] && $call->person_confirmed;
        if (! $unchanged) {
            $call->client_id = $payload['client_id'];
            $call->person_id = $payload['contact_id'];
            $call->person_confirmed = true;
            $call->save();
        }
        $this->audit($call, $payload, $actor, $unchanged ? 'no_op' : 'executed', $approver);

        return ['success' => true, 'staged' => false, 'unchanged' => (bool) $unchanged,
            'phone_call_id' => $call->id, 'client_id' => $call->client_id,
            'person_id' => $call->person_id, 'ticket_id' => $call->ticket_id];
    }

    private function audit(PhoneCall $call, array $payload, string $actor, string $status, ?int $approver = null): void
    {
        TechnicianActionLog::create([
            'actor_id' => TechnicianConfig::requiredAiActorUserId(), 'actor_label' => $actor,
            'approver_user_id' => $approver, 'action_type' => 'resolve_phone_call', 'tier' => 'approve',
            'result_status' => $status, 'ticket_id' => $call->ticket_id, 'client_id' => $payload['client_id'],
            'content_hash' => $this->hash($payload), 'correlation_id' => (string) Str::uuid(),
            'summary' => 'Phone call #'.$call->id.' client #'.$payload['client_id'].' contact #'.$payload['contact_id'].': '.$payload['reason'],
        ]);
    }

    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
