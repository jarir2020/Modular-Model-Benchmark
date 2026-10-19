<?php

// Migration lives in database/migrations so Artisan's standard runner
// discovers it, per the module contract in MODULAR_APP_PLAN.md. The timestamp
// keeps it after the tasks migration (2026_10_16_100000) because time entries
// reference the tasks table, and after every earlier module migration.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Whole minutes; per-entry validation (1–1440) is enforced in the
            // controller — the column itself only stores an unsigned integer.
            $table->unsignedInteger('duration_minutes');
            $table->date('spent_on');
            $table->text('note')->nullable();
            $table->boolean('billable')->default(true);
            $table->timestamps();

            // Common lookups: a task's logged time, a user's timesheet.
            $table->index(['task_id', 'spent_on']);
            $table->index(['user_id', 'spent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
