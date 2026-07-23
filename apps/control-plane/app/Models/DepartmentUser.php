<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** Pivot with UUIDv7 keys — plain sync() cannot fill the uuid PK otherwise. */
final class DepartmentUser extends Pivot
{
    use HasUuids;

    protected $table = 'department_user';

    public $incrementing = false;

    protected $keyType = 'string';
}
