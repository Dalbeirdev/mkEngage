<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Messaging channel (Phase 29). config carries provider credentials —
 * encrypted at rest (§18), never returned by the API in full.
 *
 * WhatsApp config keys: phone_number_id, waba_id, access_token, app_secret.
 *
 * @property array<string, mixed>|null $config
 */
final class Channel extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'type',
        'name',
        'status',
        'config',
        'webhook_verify_token',
    ];

    protected $hidden = ['config', 'webhook_verify_token'];

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
        ];
    }

    public function configString(string $key): string
    {
        $value = is_array($this->config) ? ($this->config[$key] ?? null) : null;

        return is_string($value) ? $value : '';
    }
}
