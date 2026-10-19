<?php

// Implemented with model: agnes/agnes-3.0-pro (Agnes 3.0 Pro) — Task Dependencies module, roadmap #9.

namespace Modules\TaskDependencies\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tasks\Models\Task;

class TaskDependency extends Model
{
    protected $fillable = [
        'blocking_task_id',
        'blocked_task_id',
    ];

    /**
     * The task that must be finished first.
     */
    public function blockingTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'blocking_task_id');
    }

    /**
     * The task that waits on the blocker.
     *
     * Both relations point at the same Task model: one row per directed edge
     * is how this module keeps the self-reference, instead of a recursive
     * relation on Task itself.
     */
    public function blockedTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'blocked_task_id');
    }

    /**
     * Edges where either end belongs to the given project. Because a valid
     * dependency links two tasks inside one project (enforced on write),
     * checking the blocker's project is enough.
     *
     * An empty or missing id leaves the query unfiltered, like
     * Task::scopeForProject().
     *
     * @param  Builder<static>  $query
     */
    public function scopeForProject(Builder $query, int|string|null $projectId): void
    {
        $query->when(
            $projectId !== null && $projectId !== '',
            fn (Builder $q): Builder => $q->where(
                fn (Builder $sub): Builder => $sub
                    ->whereHas('blockingTask', fn (Builder $t): Builder => $t->where('project_id', $projectId))
                    ->orWhereHas('blockedTask', fn (Builder $t): Builder => $t->where('project_id', $projectId)),
            ),
        );
    }
}
