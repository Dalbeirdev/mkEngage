<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Transactional outbox (ADR-005, RULES-messaging): event rows are
     * committed in the SAME transaction as the business rows they describe;
     * the relay publishes them to NATS JetStream and marks them published.
     *
     * Deliberately NOT RLS-scoped (like personal_access_tokens): the relay
     * is a trusted platform process that must read across organizations.
     * Payloads are data-minimized envelopes (content previews, never full
     * bodies) per the event contracts; org scoping travels IN the envelope.
     * Listed in RLS_EXEMPT with this reason.
     */
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();          // envelope id == Nats-Msg-Id (dedup)
            $table->string('event_type', 100);      // e.g. conv.message.accepted.v1
            $table->uuid('organization_id');
            $table->jsonb('envelope');              // full CloudEvents-compatible envelope
            $table->timestampTz('created_at');
            $table->timestampTz('published_at')->nullable();

            $table->index(['published_at', 'created_at']); // relay scan
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
