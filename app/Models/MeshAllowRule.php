<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Mesh allow rule this PSA created, and the state machine that ends it.
 *
 * Mesh does not expire its own rules (measured 2026-09-01 — `date_expiry` is
 * display only), so a row here is the sole reason any rule this system writes
 * ever goes away. See the migration for what each state means.
 */
class MeshAllowRule extends Model
{
    public const STATE_ACTIVE = 'active';

    /**
     * Created (or possibly created) and not settled: something the PSA needed
     * is missing. WHICH thing is missing is recorded on `scope_proved`, not on
     * this state — a row here may be missing only its upstream rule id, or it
     * may be missing the scope proof that only the create response could give.
     */
    public const STATE_UNRESOLVED = 'unresolved';

    /** Deleted AND proved absent by a 404 on the detail read. */
    public const STATE_REAPED = 'reaped';

    public const STATE_REAP_FAILED = 'reap_failed';

    /**
     * Deleted on purpose by an approved mesh_remove_allow_rule (#1134), and
     * proved absent by the same 404 detail read the reaper uses. A separate
     * state from STATE_REAPED, not a synonym: reaped means the PSA honoured a
     * lifetime it set itself, removed means a named approver ended the rule
     * early against a ticket. Both are terminal and neither is in
     * scopeReapable(), so a removed row is out of the reaper's queue by
     * construction.
     *
     * There is deliberately NO `remove_failed` twin. A removal whose
     * post-condition did not hold leaves the rule LIVE upstream and still
     * subject to whatever expiry the row carries, so the row must stay in a
     * state the reaper still works — it goes to STATE_REAP_FAILED, which is
     * exactly that queue, and the fault is surfaced to the approver.
     */
    public const STATE_REMOVED = 'removed';

    protected $fillable = [
        'client_id',
        'ticket_id',
        'technician_run_id',
        'mesh_customer_id',
        'sender',
        'comment',
        'mesh_rule_id',
        'expires_at',
        'state',
        'scope_proved',
        'created_by_actor',
        'approver_user_id',
        'upstream_created_by',
        'reaped_at',
        'removed_at',
        'last_error',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'reaped_at' => 'datetime',
        'removed_at' => 'datetime',
        'scope_proved' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * A rule the caller asked to be permanent (#1133). NULL `expires_at` is
     * the only representation of that, and it is deliberately a null rather
     * than a far-future date: a sentinel date is a lie the reaper would one
     * day act on, and it would read as an ordinary expiry on the approval
     * card.
     */
    public function isPermanent(): bool
    {
        return $this->expires_at === null;
    }

    /**
     * Rows the reaper should act on: past expiry and not already proved gone.
     * `unresolved` is included deliberately — those rules ARE live upstream
     * and expiring them requires resolving the id first, so excluding them
     * would quietly make them permanent.
     *
     * `whereNotNull` is load-bearing, not defensive (#1133). A permanent rule
     * has NULL expiry, and `expires_at <= now()` is NULL-safe in SQL — it
     * evaluates to NULL, not true, so such a row would not be selected on
     * MySQL/MariaDB/sqlite today. The predicate is written anyway because the
     * reaper is the one thing standing between this table and a customer's
     * mail filtering: the exclusion of permanent rows must be something the
     * query SAYS, not something a NULL-comparison rule happens to give us.
     *
     * Excluded from reaping is not abandoned (#1133): a permanent row that
     * never settled, and an unexpired dated row that never settled, are picked
     * up by scopeUnsettledUnexpired() below, which identifies them and never
     * deletes anything.
     */
    public function scopeReapable(Builder $query): Builder
    {
        return $query
            ->whereIn('state', [self::STATE_ACTIVE, self::STATE_UNRESOLVED, self::STATE_REAP_FAILED])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    /**
     * Unexpired rows the PSA has not settled: the same two states the
     * duplicate brake in StaffMeshAdminToolExecutor::unsettledAllowRule()
     * matches, restricted to rows that are NOT due for reaping. That means a
     * permanent row (NULL expiry) or a dated row whose expiry is still in
     * the future.
     *
     * No reap pass selects such a row yet. A permanent row never will, and a
     * dated one only once its expiry passes, which may be months away. But
     * the row is not inert: while it sits unsettled, the brake refuses every
     * later allow rule for its sender. The reaper records whatever upstream
     * id it can recover for such a row (identify only, never delete). What
     * that settles depends on the row: an UNRESOLVED row whose 201 proved
     * scope was missing nothing but its id, so it goes active once a read
     * finds exactly one rule; a row whose scope was never proved is not
     * settled by an id and stays counted as unresolved; a reap_failed row
     * records a removal that did not prove the rule absent, so it is never
     * settled here and stays reap_failed.
     *
     * The future-only bound is the complement of scopeReapable()'s
     * `expires_at <= now()`: a row due for reaping is the reap pass's, never
     * this one's, so a settle can never turn a row that should be deleted
     * into an active one.
     * See MeshAllowRuleReaper::settleUnexpired().
     */
    public function scopeUnsettledUnexpired(Builder $query): Builder
    {
        return $query
            ->whereIn('state', [self::STATE_UNRESOLVED, self::STATE_REAP_FAILED])
            ->where(function (Builder $q): void {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }
}
