<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Internal agent note (private to the org). Never a Message — it cannot enter
 * the conversation sequence or a transcript.
 *
 * @property Carbon|null $created_at
 */
final class ConversationNote extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'conversation_id',
        'author_id',
        'body',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return array<string, mixed> */
    public function toContract(): array
    {
        return [
            'note_id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'author_id' => $this->author_id,
            'author_name' => $this->author?->name,
            'body' => $this->body,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
