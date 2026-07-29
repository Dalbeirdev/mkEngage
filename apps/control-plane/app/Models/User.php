<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property string|null $two_factor_secret Decrypted by the encrypted cast.
 * @property list<string>|null $two_factor_recovery_codes Decrypted by the encrypted:array cast.
 * @property Carbon|null $two_factor_confirmed_at
 */
final class User extends Authenticatable
{
    use BelongsToOrganization;
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuids;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'email',
        'password',
        'status',
        'availability',
        'max_open_conversations',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'password' => 'hashed',
        ];
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    /** @return BelongsToMany<Department, $this, DepartmentUser> */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'department_user')
            ->using(DepartmentUser::class);
    }

    public function hasPermission(string $key): bool
    {
        return $this->roles()
            ->whereHas('permissions', fn ($query) => $query->where('key', $key))
            ->exists();
    }
}
