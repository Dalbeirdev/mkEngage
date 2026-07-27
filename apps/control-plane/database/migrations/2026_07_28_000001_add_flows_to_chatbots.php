<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 27 (mkEngage Flow v1): a visual conversation flow per chatbot.
 * The graph definition lives on the chatbot; per-conversation execution
 * state (current node, awaited reply, collected variables) on the
 * conversation. Both json — the engine validates shape on write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbots', function (Blueprint $table): void {
            $table->json('flow')->nullable();
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->json('flow_state')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chatbots', function (Blueprint $table): void {
            $table->dropColumn('flow');
        });
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn('flow_state');
        });
    }
};
