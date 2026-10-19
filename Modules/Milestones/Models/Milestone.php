<?php

/* Implemented by Solar Pro 3 (Claude Code) */

namespace Modules\Milestones\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations;
use Modules\Projects\Models\Project;

class Milestone extends Model
{
    public const STATUSES = ['planned', 'active', 'completed'];

    protected $fillable = [
        'project_id',
        'name',
        'description',
        'due_date',
        'status',
    ];

    protected $casts = [
        'due_date' => 'date',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'milestone_tasks')
            ->withPivot('created_at', 'updated_at');
    }

    public function tasksInverse(): BelongsToMany
    {
        // Inverse of the pivot table
        return $this->belongsToMany(Task::class, 'milestone_tasks')
            ->withPivot('created_at', 'updated_at')
            ->foreignKey('milestone_id')
            ->otherKey('task_id')
            ->withTimestamps();
    }

    public function setDefaultStatus(): void
    {
        $this->status = $this->STATUSES[0];
    }
}
