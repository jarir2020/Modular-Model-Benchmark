<?php

// Implemented with model: zm/atria-asi/atria-dawn-preview (Atria Dawn Preview,
// running as Claude Code) — Labels module, roadmap #4.

namespace Modules\Labels\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Tasks\Models\Task;

class Label extends Model
{
    /**
     * The only color form accepted on write: 6-digit hex. Shorthand like
     * "#FFF" is rejected by validation rather than silently expanded, so a
     * stored color is always exactly what the user typed.
     */
    public const COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    protected $fillable = [
        'name',
        'color',
        'description',
    ];

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'label_task')
            ->withTimestamps();
    }
}
