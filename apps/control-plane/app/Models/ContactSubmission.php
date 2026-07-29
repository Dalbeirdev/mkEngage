<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A message from the public website contact form. Platform-global (no tenant),
 * so no BelongsToOrganization / RLS.
 */
final class ContactSubmission extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'email',
        'company',
        'subject',
        'message',
    ];
}
