<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Per-agent read cursor on a conversation (Phase 33). */
final class ConversationRead extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'conversation_id',
        'user_id',
        'last_read_sequence',
    ];

    protected function casts(): array
    {
        return [
            'last_read_sequence' => 'integer',
        ];
    }
}
