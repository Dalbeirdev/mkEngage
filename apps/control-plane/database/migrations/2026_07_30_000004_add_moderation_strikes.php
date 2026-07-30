<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moderation strike counter on conversations: each visitor message the
 * profanity filter had to mask adds a strike; at the org's configured
 * threshold the conversation auto-closes and is marked spam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->unsignedSmallInteger('moderation_strikes')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn('moderation_strikes');
        });
    }
};
