<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Scoped machine API key (Phase 35, §15). Auth infrastructure — like
 * Sanctum PATs this table is NOT RLS-scoped: its denormalized
 * organization_id establishes tenant context. Plaintext keys are never
 * stored; only the sha256 hash and a display prefix.
 *
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_used_at
 */
final class ApiKey extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'name',
        'prefix',
        'key_hash',
        'last_used_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function findActiveByPlaintext(string $plaintext): ?self
    {
        return self::query()
            ->where('key_hash', hash('sha256', $plaintext))
            ->whereNull('revoked_at')
            ->first();
    }
}
