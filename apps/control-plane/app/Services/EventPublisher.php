<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Transactional outbox writer (ADR-005): builds a CloudEvents-compatible
 * envelope (contracts/events/envelope.schema.json) and inserts it into
 * outbox_events INSIDE the caller's transaction — the event becomes fact
 * exactly when the business rows do. The relay (outbox:relay) publishes.
 */
final class EventPublisher
{
    /** @param array<string, mixed> $data */
    public function record(
        string $type,
        string $organizationId,
        array $data,
        ?string $actorId = null,
        ?string $correlationId = null,
    ): string {
        $eventId = (string) Str::uuid7();

        $envelope = [
            'specversion' => '1.0',
            'id' => $eventId,
            'type' => $type,
            'source' => 'control-plane',
            'time' => now()->toIso8601String(),
            'orgid' => $organizationId,
            'actorid' => $actorId,
            'correlationid' => $correlationId ?? (string) Str::uuid7(),
            'causationid' => null,
            'dataschema' => 'https://contracts.mkengage.dev/events/'.$type.'/schema.json',
            'datacontenttype' => 'application/json',
            'data' => $data,
        ];

        DB::table('outbox_events')->insert([
            'id' => $eventId,
            'event_type' => $type,
            'organization_id' => $organizationId,
            'envelope' => json_encode($envelope, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return $eventId;
    }
}
