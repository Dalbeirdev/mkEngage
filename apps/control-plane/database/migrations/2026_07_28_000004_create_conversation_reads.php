<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 33: per-agent read cursors. unread = conversation.last_sequence -
 * last_read_sequence (floored at 0), computed on read — no counters to
 * drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_reads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('conversation_id');
            $table->uuid('user_id');
            $table->unsignedBigInteger('last_read_sequence')->default(0);
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->unique(['conversation_id', 'user_id']);
            $table->index('organization_id');
        });

        Rls::enable('conversation_reads');
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_reads');
    }
};
