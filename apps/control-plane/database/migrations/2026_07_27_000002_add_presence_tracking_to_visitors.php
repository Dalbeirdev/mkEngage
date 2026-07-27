<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 24 (live visitor tracking): the widget heartbeat keeps last_seen_at
 * fresh and, consent permitting, records where the visitor currently is.
 * "Live" is derived (last_seen_at within the liveness window) — no boolean
 * to go stale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table): void {
            $table->string('current_url', 2048)->nullable();
            $table->string('page_title', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table): void {
            $table->dropColumn(['current_url', 'page_title']);
        });
    }
};
