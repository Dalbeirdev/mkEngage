<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * Anonymous browser identity (ASSUMPTIONS A4). Authenticates via a
 * visitor-scoped Sanctum token issued by the widget session endpoint — the
 * token carries only the "widget" ability, so it can never reach admin/user
 * routes (ADR-009 least privilege).
 *
 * @property Carbon|null $last_seen_at
 * @property string|null $current_url
 * @property string|null $page_title
 */
final class Visitor extends Model
{
    use BelongsToOrganization;
    use HasApiTokens;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'contact_id',
        'display_name',
        'consent_state',
        'last_seen_at',
        'current_url',
        'page_title',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return HasMany<Conversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
