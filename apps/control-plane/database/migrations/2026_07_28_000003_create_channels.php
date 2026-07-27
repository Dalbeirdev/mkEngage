<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 29: messaging channels (§6 omnichannel) — WhatsApp first. The
 * channel row owns the provider credentials (encrypted casts on the model);
 * conversations pin the channel they arrived on plus the provider-side
 * thread key (WhatsApp: the sender's wa_id) so follow-ups reuse the thread.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('type', 20); // whatsapp (messenger/telegram later)
            $table->string('name', 100);
            $table->string('status', 10)->default('active'); // active|disabled
            $table->text('config'); // encrypted json (tokens, ids)
            $table->string('webhook_verify_token', 64);
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index(['organization_id', 'type']);
        });

        Rls::enable('channels');

        Schema::table('conversations', function (Blueprint $table): void {
            $table->uuid('channel_id')->nullable();
            $table->string('external_thread_id', 64)->nullable(); // WhatsApp wa_id
            $table->index(['organization_id', 'channel_id', 'external_thread_id'], 'conv_channel_thread_idx');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex('conv_channel_thread_idx');
            $table->dropColumn(['channel_id', 'external_thread_id']);
        });
        Schema::dropIfExists('channels');
    }
};
