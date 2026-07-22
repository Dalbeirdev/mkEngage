<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widget messaging foundation: visitors, conversations, messages.
     *
     * Messages carry the full §5 envelope. Sequence assignment is an atomic
     * per-conversation increment (RULES-message-ordering #1) — held on the
     * conversation row and incremented under row lock inside the request
     * transaction. channel_id is nullable until the channels module lands
     * (ASSUMPTIONS A3); the REST fallback path writes NULL = "web widget".
     *
     * When the Phoenix gateway arrives (ADR-002) it takes over the message
     * INSERT hot path against these same tables via its restricted DB role.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            // Public, non-secret widget bootstrap key (§4: no secrets in the widget).
            $table->string('widget_site_key', 40)->nullable()->unique();
        });

        Schema::create('visitors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('contact_id')->nullable(); // set on identification (A4)
            $table->string('display_name', 100)->nullable();
            $table->string('consent_state', 10)->default('unknown'); // granted|denied|unknown (§4 consent-aware)
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index('organization_id');
        });

        Schema::create('conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('status', 10)->default('open'); // open|pending|closed
            $table->uuid('visitor_id')->nullable();
            $table->uuid('contact_id')->nullable();
            $table->uuid('assigned_agent_id')->nullable();
            $table->uuid('department_id')->nullable();
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->string('source_url', 2048)->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();
            $table->timestampTz('closed_at')->nullable();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('visitor_id')->references('id')->on('visitors')->nullOnDelete();
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'visitor_id']);
        });

        Schema::create('messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();               // globally unique message ID (§5)
            $table->uuid('organization_id');
            $table->uuid('conversation_id');
            $table->uuid('channel_id')->nullable();      // NULL = web widget until channels land
            $table->string('sender_type', 10);           // visitor|contact|agent|chatbot|system
            $table->uuid('sender_id');
            $table->unsignedBigInteger('sequence_number');
            $table->string('content_type', 12)->default('text');
            $table->text('body');
            $table->string('lifecycle_state', 10)->default('persisted'); // §27
            $table->uuid('idempotency_key');
            $table->uuid('correlation_id')->nullable();
            $table->timestampTz('sent_at');
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->unique(['conversation_id', 'idempotency_key']); // duplicate sends return the original (§27)
            $table->unique(['conversation_id', 'sequence_number']); // total order is per conversation
        });

        Rls::enable('visitors');
        Rls::enable('conversations');
        Rls::enable('messages');
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('visitors');
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('widget_site_key');
        });
    }
};
