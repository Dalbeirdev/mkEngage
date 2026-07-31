<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * The tenancy root (ADR-007). Deliberately NOT BelongsToOrganization — this
 * table defines organization_id rather than carrying it. Reads are restricted
 * by membership/authorization at the application layer.
 *
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $plan_expires_at
 */
final class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'region',
        'white_label',
        'parent_organization_id',
        'settings',
        'widget_site_key',
        'widget_signing_secret',
    ];

    protected $hidden = [
        'widget_signing_secret',
    ];

    protected function casts(): array
    {
        return [
            'white_label' => 'boolean',
            'settings' => 'array',
            'plan_expires_at' => 'datetime',
            // Encrypted at rest (§18); KMS envelope encryption replaces the
            // app-key cipher in the production hardening pass.
            'widget_signing_secret' => 'encrypted',
        ];
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_organization_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_organization_id');
    }
}
