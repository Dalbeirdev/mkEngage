<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An agent's saved inbox filter set. Org-scoped by RLS; further narrowed to
 * the owning user in the controller.
 *
 * @property array<string, mixed> $filters
 * @property Carbon|null $created_at
 */
final class SavedView extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'user_id',
        'name',
        'filters',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return array<string, mixed> */
    public function toContract(): array
    {
        return [
            'saved_view_id' => $this->id,
            'name' => $this->name,
            'filters' => (object) ($this->filters ?? []),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
