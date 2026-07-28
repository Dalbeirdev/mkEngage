<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 38: remember the provider-side id of an outbound channel message
 * (e.g. Telegram message_id) so inbound events referencing it — reactions,
 * edits — can be mapped back to our message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->string('provider_message_id', 128)->nullable();
            $table->index(['conversation_id', 'provider_message_id'], 'msg_provider_idx');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex('msg_provider_idx');
            $table->dropColumn('provider_message_id');
        });
    }
};
