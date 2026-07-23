<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class KnowledgeChunk extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'document_id',
        'chunk_index',
        'content',
        'content_checksum',
    ];

    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
        ];
    }
}
