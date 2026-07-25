<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Internal agent notes on a conversation — PRIVATE to the org's agents,
     * never delivered to the visitor and never part of the message sequence
     * (§5): a separate table so notes can't leak into transcripts or the
     * widget. RLS-scoped like every tenant table.
     */
    public function up(): void
    {
        Schema::create('conversation_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('conversation_id');
            $table->uuid('author_id'); // the agent who wrote it
            $table->text('body');
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->foreign('author_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['conversation_id', 'created_at']);
        });

        Rls::enable('conversation_notes');
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_notes');
    }
};
