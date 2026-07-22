<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contacts (person records, ASSUMPTIONS A4) + the per-org widget signing
     * secret for verified visitor identity (ADR-009 boundary 2: HMAC computed
     * by the CUSTOMER's backend, never in the widget).
     *
     * The signing secret is encrypted at rest via Eloquent's encrypted cast
     * (§18 — envelope encryption via KMS replaces app-key encryption in the
     * production hardening pass; the column contract is identical).
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->text('widget_signing_secret')->nullable();
        });

        Schema::create('contacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('external_id', 100)->nullable(); // customer-system identifier (signed identity subject)
            $table->string('email', 255)->nullable();
            $table->string('name', 200)->nullable();
            $table->string('phone', 50)->nullable();
            $table->jsonb('custom_attributes')->default('{}');
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();
            $table->timestampTz('deleted_at')->nullable();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->unique(['organization_id', 'external_id']);
            $table->index(['organization_id', 'email']);
        });

        // visitors.contact_id / conversations.contact_id already exist (Phase 3);
        // add the FK now that contacts exists.
        Schema::table('visitors', function (Blueprint $table): void {
            $table->foreign('contact_id')->references('id')->on('contacts')->nullOnDelete();
        });
        Schema::table('conversations', function (Blueprint $table): void {
            $table->foreign('contact_id')->references('id')->on('contacts')->nullOnDelete();
        });

        Rls::enable('contacts');
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropForeign(['contact_id']);
        });
        Schema::table('visitors', function (Blueprint $table): void {
            $table->dropForeign(['contact_id']);
        });
        Schema::dropIfExists('contacts');
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('widget_signing_secret');
        });
    }
};
