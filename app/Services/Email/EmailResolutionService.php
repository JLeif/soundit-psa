<?php

namespace App\Services\Email;

use App\Models\Client;
use App\Models\Email;
use App\Models\EmailResolutionProposal;
use App\Models\User;
use App\Services\EmailService;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\DB;

/** Email-native approval: no placeholder ticket, network, or immediate lane. */
class EmailResolutionService
{
    public function stage(array $arguments, int $clientId, string $actor): array
    {
        if (TechnicianConfig::killSwitchEngaged()) {
            return ['error' => 'Technician kill-switch engaged.'];
        }
        if (array_diff(array_keys($arguments), ['email_id', 'reason']) !== []
            || ! is_int($arguments['email_id'] ?? null) || $arguments['email_id'] < 1
            || ! is_string($arguments['reason'] ?? null) || trim($arguments['reason']) === ''
            || mb_strlen($arguments['reason']) > 1000 || $clientId < 1) {
            return ['error' => 'Positive email_id and client_id and a reason (1–1000 characters) are required; no other arguments.'];
        }

        return DB::transaction(function () use ($arguments, $clientId, $actor): array {
            $client = Client::find($clientId);
            $email = Email::find($arguments['email_id']);
            if (! $client || ! $email || $email->client_id !== null) {
                return ['error' => 'Client/email not found or email already resolved.'];
            }
            $ids = Email::where('from_address', $email->from_address)->whereNull('client_id')
                ->orderBy('id')->lockForUpdate()->pluck('id')->all();
            if (! in_array($email->id, $ids, true)) {
                return ['error' => 'Sender backlog changed; re-stage.'];
            }
            $payload = ['email_id' => $email->id, 'client_id' => $clientId,
                'sender' => $email->from_address, 'email_ids' => $ids, 'reason' => trim($arguments['reason'])];
            $hash = $this->hash($payload);
            // Email cohort lock serializes same-sender staging as well as approval.
            $proposal = EmailResolutionProposal::where('content_hash', $hash)->where('state', 'pending')->first();
            $proposal ??= EmailResolutionProposal::create([
                'email_id' => $email->id, 'client_id' => $clientId, 'state' => 'pending',
                'payload' => $payload, 'content_hash' => $hash, 'email_count' => count($ids), 'drafted_by' => $actor,
            ]);

            return ['success' => true, 'staged' => true, 'proposal_id' => $proposal->id,
                'email_id' => $email->id, 'client_id' => $clientId, 'email_ids' => $ids,
                'sender_wide_count' => count($ids), 'message' => 'Sender-wide resolution held for cockpit approval; no email resolved.'];
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
            $proposal = EmailResolutionProposal::whereKey($id)->lockForUpdate()->first();
            if (! $proposal || $proposal->state !== 'pending') {
                return ['error' => 'Proposal already handled or not found.'];
            }
            try {
                $payload = $proposal->payload;
                if (! is_array($payload) || ! hash_equals($proposal->content_hash, $this->hash($payload))
                    || ($payload['email_id'] ?? null) !== $proposal->email_id
                    || ($payload['client_id'] ?? null) !== $proposal->client_id
                    || count($payload['email_ids'] ?? []) !== $proposal->email_count) {
                    throw new \DomainException('Proposal binding changed; re-stage.');
                }
                $email = Email::find($proposal->email_id);
                if (! $email || $email->from_address !== $payload['sender'] || ! Client::find($proposal->client_id)) {
                    throw new \DomainException('Sender or client changed; re-stage.');
                }
                $ids = app(EmailService::class)->linkSenderClient($email, $proposal->client_id, $payload['email_ids']);
            } catch (\DomainException $e) {
                $proposal->update(['state' => 'stale', 'handled_at' => now()]);

                return ['error' => $e->getMessage()];
            }
            $proposal->update(['state' => 'done', 'approved_by' => $approver->id, 'handled_at' => now()]);

            return ['success' => true, 'proposal_id' => $proposal->id, 'client_id' => $proposal->client_id,
                'email_ids' => $ids, 'sender_wide_count' => count($ids), 'message' => 'Sender backlog resolved; ticket creation remains with the existing intake path.'];
        });
    }

    public function deny(int $id, User $approver): array
    {
        if (! $this->canApprove($approver)) {
            return ['error' => 'Active administrator or technician permission required.'];
        }
        $changed = EmailResolutionProposal::whereKey($id)->where('state', 'pending')->update([
            'state' => 'denied', 'approved_by' => $approver->id, 'handled_at' => now(),
        ]);

        return $changed ? ['success' => true, 'message' => 'Email resolution denied.'] : ['error' => 'Proposal already handled or not found.'];
    }

    public function canApprove(User $user): bool
    {
        $current = User::find($user->id);

        return $current && $current->is_active && ($current->isAdmin() || $current->isTech());
    }

    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
