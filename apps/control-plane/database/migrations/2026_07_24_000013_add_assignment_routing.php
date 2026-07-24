<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Assignment routing v2 (§16). Departments gain a routing STRATEGY;
     * agents gain an AVAILABILITY flag and an optional concurrency CAP; the
     * department_user pivot tracks the last time each member was assigned a
     * conversation (the round-robin cursor). All columns are on already-RLS'd
     * tables — no new tenant table, so no new policy needed.
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            // round_robin | least_busy | manual
            $table->string('assignment_strategy', 20)->default('least_busy')->after('is_default');
        });

        Schema::table('users', function (Blueprint $table): void {
            // available | away — only available agents receive auto-assignments.
            $table->string('availability', 10)->default('available')->after('status');
            // NULL = unlimited; otherwise the agent is skipped once this many
            // open conversations are already assigned to them.
            $table->unsignedInteger('max_open_conversations')->nullable()->after('availability');
        });

        Schema::table('department_user', function (Blueprint $table): void {
            $table->timestampTz('last_assigned_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('departments', fn (Blueprint $t) => $t->dropColumn('assignment_strategy'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['availability', 'max_open_conversations']));
        Schema::table('department_user', fn (Blueprint $t) => $t->dropColumn('last_assigned_at'));
    }
};
