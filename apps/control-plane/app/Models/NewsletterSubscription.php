<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A newsletter opt-in from the public website footer. Unique per email;
 * platform-global (no tenant), so no BelongsToOrganization / RLS.
 */
final class NewsletterSubscription extends Model
{
    use HasUuids;

    protected $fillable = [
        'email',
        'source',
    ];
}
