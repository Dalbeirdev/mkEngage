<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moderation: per-organization IP ban list. A banned IP is refused a widget
 * session before any visitor identity or token is minted (fail closed at the
 * front door). The profanity filter is config only and rides organizations
 * .settings (json) — no table of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderation_ip_bans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('ip_address', 45); // IPv6-max width
            $table->string('reason', 255)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->unique(['organization_id', 'ip_address']);
            $table->index('organization_id');
        });

        Rls::enable('moderation_ip_bans');
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_ip_bans');
    }
};
