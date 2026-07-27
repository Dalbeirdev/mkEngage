<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One emoji reaction per reactor per message (Phase 28). Reacting again
 * with the same emoji removes it; a different emoji replaces it.
 */
final class MessageReaction extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'message_id',
        'reactor_type',
        'reactor_id',
        'emoji',
    ];
}
