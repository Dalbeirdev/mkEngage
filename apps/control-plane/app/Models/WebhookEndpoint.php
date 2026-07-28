<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Customer webhook endpoint (Phase 35). Every delivery is signed with the
 * per-endpoint secret: X-MkEngage-Signature: sha256=HMAC(raw body, secret).
 *
 * @property list<string>|null $events
 */
final class WebhookEndpoint extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'url',
        'secret',
        'events',
        'status',
    ];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
        ];
    }
}
