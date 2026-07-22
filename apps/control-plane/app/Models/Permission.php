<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Global permission catalog — deliberately NOT tenant-scoped (see the RBAC
 * migration). Grants (role_permissions) are tenant-owned and RLS-protected.
 */
final class Permission extends Model
{
    use HasUuids;

    protected $fillable = [
        'key',
        'description',
    ];

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }
}
