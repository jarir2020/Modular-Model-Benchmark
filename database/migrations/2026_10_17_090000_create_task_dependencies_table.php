<?php

// Implemented with model: agnes/agnes-3.0-pro (Agnes 3.0 Pro) — Task Dependencies module, roadmap #9.
// Migration lives in database/migrations so Artisan's standard runner discovers
// it, per the module contract in MODULAR_APP_PLAN.md. The timestamp keeps it
// after the upstream module migrations (tasks 100000, labels 120000, comments 130000)
// because task dependencies reference the tasks table twice.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A directed edge between two tasks: the blocking task must be
        // finished before the blocked task can start. Both foreign keys point
        // at `tasks`, which is where the self-reference lives; the remaining
        // invariants (no self-link, no duplicate pair, same project) are
        // validated on write in the controller.
        Schema::create('task_dependencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('blocking_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('blocked_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->timestamps();

            // Database backstop for the duplicate-link invariant: one direction
            // of one pair exists at most once. The reverse edge (B blocking A)
            // is a different pair and therefore allowed by the schema itself.
            $table->unique(['blocking_task_id', 'blocked_task_id'], 'task_dependencies_pair_unique');

            // Fast lookups from either end. The pair-unique index above already
            // covers blocking_task_id, so only the reverse side needs its own.
            $table->index('blocked_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_dependencies');
    }
};
