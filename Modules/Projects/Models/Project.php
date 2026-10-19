<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    public const STATUSES = ['planned', 'active', 'completed'];

    protected $fillable = [
        'name',
        'description',
        'status',
        'due_date',
    ];

    protected $casts = [
        'due_date' => 'date',
    ];
}
