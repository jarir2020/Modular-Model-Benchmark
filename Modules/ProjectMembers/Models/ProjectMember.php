<?php

namespace Modules\ProjectMembers\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Projects\Models\Project;

/**
 * The project ↔ user membership, modelled as a first-class row: it carries the
 * user's access level on that project, and the module's authorization reads
 * those levels back to decide who may see or change the roster.
 *
 * The (project_id, user_id) pair is the identity of a membership — a user
 * belongs to a project at most once — and is the only uniqueness the module
 * promises.
 */
class ProjectMember extends Model
{
    /**
     * The accepted access levels, ordered from least to most privileged.
     *
     * `viewer` — may see the project's work.
     * `editor` — may change the project's content.
     * `admin`  — may also add, re-level and remove members.
     */
    public const ACCESS_LEVELS = ['viewer', 'editor', 'admin'];

    /**
     * The level a row starts in when the client does not pick one.
     */
    public const DEFAULT_ACCESS_LEVEL = 'viewer';

    protected $fillable = [
        'project_id',
        'user_id',
        'access_level',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isAdmin(): bool
    {
        return $this->access_level === 'admin';
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeForProject(Builder $query, int|string|null $projectId): void
    {
        $query->when(
            $projectId !== null && $projectId !== '',
            fn (Builder $q): Builder => $q->where('project_id', $projectId),
        );
    }

    /**
     * Unknown levels are ignored rather than applied, so a hand-edited
     * ?access_level= value can never widen or empty the result set by accident.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithAccessLevel(Builder $query, ?string $accessLevel): void
    {
        $query->when(
            $accessLevel !== null && in_array($accessLevel, self::ACCESS_LEVELS, true),
            fn (Builder $q): Builder => $q->where('access_level', $accessLevel),
        );
    }

    /**
     * Match a term against the member's name or email, or against the name of
     * the project they belong to.
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
                    })->orWhereHas('project', function (Builder $project) use ($like): void {
                        $project->where('name', 'like', $like);
                    });
                });
            },
        );
    }
}
