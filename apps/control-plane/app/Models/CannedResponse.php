<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Reusable agent reply template (Phase 25). Shortcut is unique per org and
 * typed as /shortcut in the thread composer.
 */
final class CannedResponse extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'title',
        'shortcut',
        'body',
        'created_by',
    ];

    /** @return array<string, mixed> */
    public function toContract(): array
    {
        return [
            'canned_response_id' => $this->id,
            'title' => $this->title,
            'shortcut' => $this->shortcut,
            'body' => $this->body,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
