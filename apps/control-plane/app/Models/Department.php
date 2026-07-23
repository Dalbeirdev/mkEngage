<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Department extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'name',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /** @return BelongsToMany<User, $this, DepartmentUser> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'department_user')
            ->using(DepartmentUser::class);
    }
}
