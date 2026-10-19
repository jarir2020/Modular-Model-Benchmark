<?php

// Migration lives in database/migrations so Artisan's standard runner
// discovers it, per the module contract in MODULAR_APP_PLAN.md. The timestamp
// keeps it after the teams migration (2026_10_18_090000) because team members
// reference the teams table, and after every earlier module migration.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Membership state: `invited` (added, not yet active), `active`
            // (counts as a member), `suspended` (kept, but not active). The
            // controller only accepts these three values.
            $table->string('status')->default('active');
            // Set the first time a row becomes active; kept afterwards so a
            // suspended member still shows when they originally joined.
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            // The pivot identity is the (team, user) pair: a user is a member of
            // a team at most once, so adding the same pair twice is a duplicate
            // insert rather than a second membership. The controller reports the
            // violation as a 422; this index is the database backstop against a
            // concurrent double-insert.
            $table->unique(['team_id', 'user_id']);
            // Common lookups: a team's roster, a user's memberships by state.
            $table->index(['team_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_members');
    }
};
