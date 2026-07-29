<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbound marketing leads from the public website (contact form + newsletter).
 *
 * These belong to mkEngage the company, not to any tenant — there is no
 * organization_id and deliberately NO RLS (like the marketing site itself,
 * they are platform-global). The RLS guard only covers tables that carry an
 * organization_id, so these are correctly out of its scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_submissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 255);
            $table->string('email', 255);
            $table->string('company', 255)->nullable();
            $table->string('subject', 100)->nullable();
            $table->text('message');
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->index('created_at');
        });

        Schema::create('newsletter_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email', 255)->unique();
            $table->string('source', 60)->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscriptions');
        Schema::dropIfExists('contact_submissions');
    }
};
