<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * §5 message envelope. Sequence numbers define per-conversation total order
 * (RULES-message-ordering); rows are immutable once persisted — edits are
 * new facts (redaction etc.), never mutations of body/sequence.
 *
 * @property Carbon|null $sent_at
 */
final class Message extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'conversation_id',
        'channel_id',
        'sender_type',
        'sender_id',
        'sequence_number',
        'content_type',
        'body',
        'lifecycle_state',
        'idempotency_key',
        'correlation_id',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence_number' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return array<string, mixed> Contract-shaped payload (OpenAPI Message schema). */
    public function toContract(): array
    {
        return [
            'message_id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'channel_id' => $this->channel_id,
            'sender_type' => $this->sender_type,
            'sender_id' => $this->sender_id,
            'sequence_number' => $this->sequence_number,
            'content_type' => $this->content_type,
            'body' => $this->body,
            'lifecycle_state' => $this->lifecycle_state,
            'sent_at' => $this->sent_at?->toIso8601String(),
        ];
    }
}
