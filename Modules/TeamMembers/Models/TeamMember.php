<?php

namespace Modules\TeamMembers\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Teams\Models\Team;

/**
 * The team ↔ user pivot, modelled as a first-class row: it carries its own id,
 * a membership state and a join date, and the module exposes it as a page of
 * memberships rather than as a relation behind other pages.
 *
 * The (team_id, user_id) pair is the identity of a membership — a user belongs
 * to a team at most once — and is the only uniqueness the module promises.
 */
class TeamMember extends Model
{
    /**
     * The only membership states accepted on write.
     *
     * `invited`   — the user has been put on the team but is not counted yet.
     * `active`    — the user is a member; `joined_at` is stamped on arrival.
     * `suspended` — the user keeps their row (and join date) but is not active.
     */
    public const STATUSES = ['invited', 'active', 'suspended'];

    /**
     * The state a row starts in when the client does not pick one.
     */
    public const DEFAULT_STATUS = 'active';

    protected $fillable = [
        'team_id',
        'user_id',
        'status',
        'joined_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Who may change or remove this membership:
     *
     *  - the owner of the team, for every row on it, and
     *  - the member themself, so a user can leave a team without an owner.
     *
     * Returns false for a guest so callers never have to null-check. A member
     * can remove themself but cannot re-state themself: `update` is owner-only
     * because changing a state is an administrative act.
     */
    public function isManagedBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ((int) $this->user_id === (int) $user->getKey()) {
            return true;
        }

        return $this->team?->isOwnedBy($user) === true;
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeForTeam(Builder $query, int|string|null $teamId): void
    {
        $query->when(
            $teamId !== null && $teamId !== '',
            fn (Builder $q): Builder => $q->where('team_id', $teamId),
        );
    }

    /**
     * Unknown states are ignored rather than applied, so a hand-edited
     * ?status= value can never widen or empty the result set by accident.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithStatus(Builder $query, ?string $status): void
    {
        $query->when(
            $status !== null && in_array($status, self::STATUSES, true),
            fn (Builder $q): Builder => $q->where('status', $status),
        );
    }

    /**
     * Match a term against the member's name or email, or against the name of
     * the team they belong to.
     *
     * @param  Builder<static>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $query->when(
            $term !== null && $term !== '',
            function (Builder $q) use ($term): void {
                $like = '%'.$term.'%';

                $q->where(function (Builder $inner) use ($like): void {
                    $inner->whereHas('user', function (Builder $user) use ($like): void {
                        $user->where('name', 'like', $like)
                            ->orWhere('email', 'like', $like);
                    })->orWhereHas('team', function (Builder $team) use ($like): void {
                        $team->where('name', 'like', $like);
                    });
                });
            },
        );
    }
}
