<?php

// Migration lives in database/migrations so Artisan's standard runner
// discovers it, per the module contract in MODULAR_APP_PLAN.md. The timestamp
// keeps it after the teams / team members migrations (2026_10_18_*), whose
// rows a role is scoped to and assigned onto, and after every earlier module
// migration.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->timestamps();

            // Scoped uniqueness: a role name is only meaningful inside its own
            // team, so "Designer" may exist on two teams but only once within
            // one. The controller reports a violation as a 422; this index is
            // the database backstop against a concurrent double-insert.
            $table->unique(['team_id', 'name']);
        });

        // Assignment pivot between roles and team memberships. It carries
        // timestamps (the models use withTimestamps()) so the page can show
        // when a role was handed out.
        Schema::create('role_assignments', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('team_member_id')->constrained('team_members')->cascadeOnDelete();
            $table->timestamps();

            // A member holds a given role at most once; assigning again is a
            // duplicate insert rather than a second assignment.
            $table->primary(['role_id', 'team_member_id']);
            // Common lookup: every role a membership carries.
            $table->index('team_member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('roles');
    }
};
