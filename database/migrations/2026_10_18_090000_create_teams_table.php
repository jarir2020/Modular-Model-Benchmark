<?php

// Migration lives in database/migrations so Artisan's standard runner
// discovers it, per the module contract in MODULAR_APP_PLAN.md. The timestamp
// keeps it after every earlier module migration; teams only reference the host
// `users` table, which is created by the baseline users migration.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            // The owner is the authorization boundary for this module: only the
            // owning user may rename, re-describe or delete a team. Membership
            // (module 12) will widen read access later, but never write access
            // beyond the owner without an explicit role.
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            // Team names are unique so "a team" is unambiguous in pickers and
            // in the authorization checks keyed by name.
            $table->unique('name');
            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};