<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 28: message interactions — emoji reactions (one per reactor per
 * message, Teams-style toggle/replace) and quoted replies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_reactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('message_id');
            $table->string('reactor_type', 10); // visitor|agent
            $table->uuid('reactor_id');
            $table->string('emoji', 16);
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('message_id')->references('id')->on('messages')->cascadeOnDelete();
            $table->unique(['message_id', 'reactor_type', 'reactor_id']);
            $table->index('organization_id');
        });

        Rls::enable('message_reactions');

        Schema::table('messages', function (Blueprint $table): void {
            $table->uuid('reply_to_message_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_reactions');
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn('reply_to_message_id');
        });
    }
};
