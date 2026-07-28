<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Append-only (ADR-009): no update/delete surface. UPDATED_AT is disabled and
 * mutation methods are hard-blocked; retention/redaction run as controlled
 * platform workflows (§29), not model operations.
 *
 * @property string $action
 * @property Carbon|null $created_at
 */
final class AuditLogEntry extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'audit_log';

    protected $fillable = [
        'organization_id',
        'actor',
        'action',
        'subject_type',
        'subject_id',
        'context',
        'ip',
        'correlation_id',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @param array<string, mixed> $context */
    public static function record(
        string $actor,
        string $action,
        ?Model $subject = null,
        array $context = [],
        ?string $ip = null,
        ?string $correlationId = null,
    ): self {
        return self::query()->create([
            'actor' => $actor,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'context' => $context,
            'ip' => $ip,
            'correlation_id' => $correlationId,
        ]);
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \LogicException('Audit log entries are append-only.');
    }

    public function delete(): ?bool
    {
        throw new \LogicException('Audit log entries are append-only.');
    }
}
