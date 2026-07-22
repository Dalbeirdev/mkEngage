<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Contact extends Model
{
    use BelongsToOrganization;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'external_id',
        'email',
        'name',
        'phone',
        'custom_attributes',
    ];

    protected function casts(): array
    {
        return [
            'custom_attributes' => 'array',
        ];
    }

    /** @return HasMany<Visitor, $this> */
    public function visitors(): HasMany
    {
        return $this->hasMany(Visitor::class);
    }

    /** @return HasMany<Conversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /** @return array<string, mixed> Contract-shaped payload (OpenAPI Contact schema). */
    public function toContract(): array
    {
        return [
            'contact_id' => $this->id,
            'organization_id' => $this->organization_id,
            'external_id' => $this->external_id,
            'email' => $this->email,
            'name' => $this->name,
            'phone' => $this->phone,
            // (object) cast: an empty PHP array would serialize as JSON []
            // but the contract requires an object ({}).
            'attributes' => (object) ($this->custom_attributes ?? []),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
