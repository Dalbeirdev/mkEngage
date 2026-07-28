<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 35 (§15 developer platform): scoped machine API keys (sha256-hashed,
 * plaintext shown exactly once) and customer webhook endpoints (per-endpoint
 * signing secret, event subscriptions).
 *
 * api_keys is auth infrastructure like personal_access_tokens: the key
 * lookup happens BEFORE tenant context exists, so the table is deliberately
 * NOT RLS-scoped — its denormalized organization_id is what establishes the
 * context (same pattern as Sanctum PATs, see EstablishTenantContext).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('name', 100);
            $table->string('prefix', 16); // display: mk_live_ab12…
            $table->string('key_hash', 64)->unique();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index('organization_id');
        });

        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('url', 2048);
            $table->text('secret'); // encrypted cast on the model
            $table->json('events'); // subscribed event names
            $table->string('status', 10)->default('active');
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index('organization_id');
        });

        Rls::enable('webhook_endpoints');
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('api_keys');
    }
};
