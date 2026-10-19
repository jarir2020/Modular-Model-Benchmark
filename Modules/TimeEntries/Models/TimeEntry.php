<?php

namespace Modules\TimeEntries\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tasks\Models\Task;

class TimeEntry extends Model
{
    protected $fillable = [
        'task_id',
        'user_id',
        'duration_minutes',
        'spent_on',
        'note',
        'billable',
    ];

    protected $casts = [
        'spent_on' => 'date',
        'billable' => 'boolean',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeForTask(Builder $query, int|string|null $taskId): void
    {
        $query->when(
            $taskId !== null && $taskId !== '',
            fn (Builder $q): Builder => $q->where('task_id', $taskId),
        );
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeForUser(Builder $query, int|string|null $userId): void
    {
        $query->when(
            $userId !== null && $userId !== '',
            fn (Builder $q): Builder => $q->where('user_id', $userId),
        );
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeBillable(Builder $query, ?string $billable): void
    {
        $query->when(
            $billable === '1' || $billable === '0',
            fn (Builder $q): Builder => $q->where('billable', (bool) (int) $billable),
        );
    }
}
