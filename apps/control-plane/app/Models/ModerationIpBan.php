<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A single IP address barred from opening a widget session for this org
 * (moderation). Unique per (organization, ip_address); the global org scope
 * keeps one tenant's ban list invisible to another.
 */
final class ModerationIpBan extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'ip_address',
        'reason',
        'created_by',
    ];

    /** @return array<string, mixed> */
    public function toContract(): array
    {
        return [
            'ip_ban_id' => $this->id,
            'ip_address' => $this->ip_address,
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
