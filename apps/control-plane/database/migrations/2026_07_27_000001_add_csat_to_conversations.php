<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 23 (CSAT): post-conversation satisfaction rating captured from the
 * widget after close. Pre-chat + business-hours configuration live in the
 * organizations.settings json column (no schema change needed there).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->unsignedTinyInteger('csat_rating')->nullable(); // 1..5
            $table->string('csat_comment', 1000)->nullable();
            $table->timestampTz('csat_rated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn(['csat_rating', 'csat_comment', 'csat_rated_at']);
        });
    }
};
