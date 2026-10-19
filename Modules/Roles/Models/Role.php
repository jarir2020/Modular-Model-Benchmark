<?php

namespace Modules\Roles\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Teams\Models\Team;
use Modules\TeamMembers\Models\TeamMember;

/**
 * A named role scoped to one team. The (team_id, name) pair is what the module
 * treats as unique: "Designer" is a different role on two different teams, but
 * a team may only define it once, so role names stay unambiguous in its own
 * pickers and assignment lists.
 *
 * Assignment is many-to-many: a role can be held by several members of its
 * team, and a member may hold several roles. The pivot enforces that a given
 * member holds a given role at most once, and every assignment stays inside a
 * single team — the controller refuses to pair a role with a member of a
 * different team.
 */
class Role extends Model
{
    protected $fillable = [
        'team_id',
        'name',
        'description',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The team memberships that hold this role. Timestamps live on the pivot
     * (role_assignments) so the page can show when each role was handed out.
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(TeamMember::class, 'role_assignments')
            ->withTimestamps();
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
     * Match a term against the role's name or description, or against the name
     * of the team it belongs to.
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
                    $inner->where('name', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhereHas('team', function (Builder $team) use ($like): void {
                            $team->where('name', 'like', $like);
                        });
                });
            },
        );
    }
}
