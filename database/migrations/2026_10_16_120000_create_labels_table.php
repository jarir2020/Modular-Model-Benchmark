<?php

// Implemented with model: zm/atria-asi/atria-dawn-preview (Atria Dawn Preview,
// running as Claude Code) — Labels module, roadmap #4.
// Migration lives in database/migrations so Artisan's standard runner discovers
// it, per the module contract in MODULAR_APP_PLAN.md. The timestamp keeps it
// after 2026_10_16_100000_create_tasks_table because labels attach to tasks.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labels', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('color', 32)->default('#6b7280');
            $table->text('description')->nullable();
            $table->timestamps();

            // A label's name is the reusable identifier, so duplicates are meaningless.
            $table->unique('name');
            $table->index('color');
        });

        // Pivot for the many-to-many relationship between labels and tasks.
        // Both models use withTimestamps(), so the pivot needs timestamps too.
        Schema::create('label_task', function (Blueprint $table): void {
            $table->foreignId('label_id')->constrained('labels')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->timestamps();

            // A task can carry a given label only once; detaching is idempotent.
            $table->primary(['label_id', 'task_id']);
            $table->index('task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_task');
        Schema::dropIfExists('labels');
    }
};
