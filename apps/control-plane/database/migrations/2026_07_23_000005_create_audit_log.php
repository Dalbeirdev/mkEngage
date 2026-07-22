<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit log (ADR-009 §9). Tenant-owned and RLS-scoped; the
     * application layer exposes no update/delete paths (Eloquent model is
     * create-only). Month-range partitioning is applied when running on
     * PostgreSQL in a later hardening pass alongside pg_partman (ADR-006);
     * the logical schema is stable from day one.
     */
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('actor', 120);             // actor format: user:{uuid} | apikey:{uuid} | system | platform:{uuid}
            $table->string('action', 100);            // e.g. user.role.granted, auth.login.succeeded
            $table->string('subject_type', 100)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->jsonb('context')->default('{}');  // data-minimized details; never secrets (ADR-008 allow-list discipline)
            $table->string('ip', 45)->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'action']);
        });

        Rls::enable('audit_log');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
