<?php

namespace App\Modules\Attachments\Models;

use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    protected $fillable = ['task_id', 'file_path', 'file_name', 'file_size'];

    // Relationships
    public function task() {
        return $this->belongsTo(Task::class);
    }
}
?>