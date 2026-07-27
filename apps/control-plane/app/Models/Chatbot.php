<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed>|null $flow
 */
final class Chatbot extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'name',
        'status',
        'system_prompt',
        'provider',
        'model',
        'flow',
    ];

    protected function casts(): array
    {
        return [
            'flow' => 'array',
        ];
    }
}
