<?php

// Implemented with model: kr/qwen3-coder-next (Qwen 3 Coder Next) — Comments module, roadmap #5.

namespace Modules\Comments\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tasks\Models\Task;

class Comment extends Model
{
    protected $fillable = [
        'task_id',
        'user_id',
        'body',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
