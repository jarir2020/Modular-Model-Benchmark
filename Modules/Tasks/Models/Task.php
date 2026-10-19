<?php

// Implemented with model: agnes/agnes-3.0-pro (Agnes 3.0 Pro) — Tasks module, roadmap #2.

namespace Modules\Tasks\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Labels\Models\Label;
use Modules\Projects\Models\Project;

class Task extends Model
{
    public const STATUSES = ['todo', 'in_progress', 'done'];

    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    /**
     * Sort weight for list ordering: lower runs first, so urgent leads.
     */
    public const PRIORITY_WEIGHTS = [
        'urgent' => 0,
        'high' => 1,
        'medium' => 2,
        'low' => 3,
    ];

    protected $fillable = [
        'project_id',
        'title',
        'description',
        'status',
        'priority',
        'due_date',
        'assignee_id',
    ];

    protected $casts = [
        'due_date' => 'date',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * Labels attached to this task. Added by the Labels module (roadmap #4);
     * the other side of the `label_task` pivot defined there.
     */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'label_task')
            ->withTimestamps();
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
     * @param  Builder<static>  $query
     */
    public function scopeWithStatus(Builder $query, ?string $status): void
    {
        $query->when(
            $status !== null && $status !== '' && in_array($status, self::STATUSES, true),
            fn (Builder $q): Builder => $q->where('status', $status),
        );
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeWithPriority(Builder $query, ?string $priority): void
    {
        $query->when(
            $priority !== null && $priority !== '' && in_array($priority, self::PRIORITIES, true),
            fn (Builder $q): Builder => $q->where('priority', $priority),
        );
    }

    /**
     * Filter by assignee. Accepts a user id, the literal "unassigned",
     * or null/empty string to leave the query unfiltered.
     *
     * @param  Builder<static>  $query
     */
    public function scopeForAssignee(Builder $query, int|string|null $assigneeId): void
    {
        if ($assigneeId === null || $assigneeId === '') {
            return;
        }

        if ($assigneeId === 'unassigned') {
            $query->whereNull('assignee_id');

            return;
        }

        $query->where('assignee_id', $assigneeId);
    }
}
