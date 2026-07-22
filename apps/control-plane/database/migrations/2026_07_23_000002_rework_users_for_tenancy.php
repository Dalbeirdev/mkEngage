<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rebuild the skeleton users table as a tenant-owned table: UUIDv7 keys,
     * mandatory organization_id (RULES-tenant-isolation #1), MFA columns for
     * Fortify. Email is unique PER ORGANIZATION (the same address may exist in
     * two orgs, e.g. an agency worker); login therefore resolves org first
     * (global auth-routing layer, ADR-010 §28).
     */
    public function up(): void
    {
        Schema::dropIfExists('users'); // skeleton table, empty at this point

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('name', 200);
            $table->string('email', 255);
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password');
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestampTz('two_factor_confirmed_at')->nullable();
            $table->string('status', 20)->default('active'); // active | suspended | deprovisioned (SCIM, ADR-009)
            $table->rememberToken();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();
            $table->timestampTz('deleted_at')->nullable();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->unique(['organization_id', 'email']);
            $table->index('organization_id');
        });

        Rls::enable('users');
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
