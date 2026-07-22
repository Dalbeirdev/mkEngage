<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Organizations are the tenancy root (ADR-007). They are NOT themselves
     * RLS-scoped by organization_id (they define it); access is restricted by
     * membership checks at the application layer and by the platform role.
     */
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 200);
            $table->string('slug', 100)->unique();
            $table->string('region', 8)->default('us'); // Home data region (§28)
            $table->boolean('white_label')->default(false);
            $table->uuid('parent_organization_id')->nullable(); // Reseller parent (ASSUMPTIONS A8)
            $table->jsonb('settings')->default('{}');
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();
            $table->timestampTz('deleted_at')->nullable();

            $table->foreign('parent_organization_id')
                ->references('id')->on('organizations')->nullOnDelete();
            $table->index('parent_organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
