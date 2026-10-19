<?php

// Migration lives in database/migrations so Artisan's standard runner
// discovers it, per the module contract in MODULAR_APP_PLAN.md. The timestamp
// keeps it after the projects migration (2026_10_16_000000) and after the
// teams / team members migrations (2026_10_18_*), whose membership data the
// module reads for authorization, and after every earlier module migration.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Project access level: `viewer` reads the project, `editor` works
            // on its content, `admin` manages the roster itself. The controller
            // only accepts these three values.
            $table->string('access_level', 20)->default('viewer');
            $table->timestamps();

            // The pivot identity is the (project, user) pair: a user is a member
            // of a project at most once, so the same pair twice is a duplicate
            // insert rather than a second membership. The controller reports
            // the violation as a 422; this index is the database backstop
            // against a concurrent double-insert.
            $table->unique(['project_id', 'user_id']);
            // Common lookups: a project's roster by level, a user's projects.
            $table->index(['project_id', 'access_level']);
            $table->index(['user_id', 'access_level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_members');
    }
};
