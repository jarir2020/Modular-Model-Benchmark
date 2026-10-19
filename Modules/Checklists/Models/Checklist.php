<?php

// Implemented with model: claude-sonnet-5-5 (Claude Sonnet 5.5) — Checklists module, roadmap #7.

namespace Modules\Checklists\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tasks\Models\Task;

class Checklist extends Model
{
    protected $fillable = [
        'task_id',
        'title',
        'position',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
