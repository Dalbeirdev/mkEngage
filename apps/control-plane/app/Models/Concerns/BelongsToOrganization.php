<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Tenancy\MissingTenantContextException;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Application-layer tenant scoping (ADR-007 layer 1; PostgreSQL RLS is
 * layer 2). Every tenant-owned model uses this trait:
 *
 *  - all queries are filtered to the current organization,
 *  - creates have organization_id filled from context and are rejected when
 *    it contradicts an explicitly set value (defense in depth),
 *  - no query runs without established context (fail closed) — the global
 *    scope throws instead of silently widening.
 *
 * Escaping the scope requires the named PlatformScope bypass used only in
 * vetted platform-administration namespaces (lint-guarded, RULES-tenant-
 * isolation #5).
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $builder): void {
            /** @var TenantContext $context */
            $context = app(TenantContext::class);

            $builder->where(
                $builder->getModel()->qualifyColumn('organization_id'),
                $context->organizationId(), // throws MissingTenantContextException when unset
            );
        });

        static::creating(function (Model $model): void {
            /** @var TenantContext $context */
            $context = app(TenantContext::class);
            $organizationId = $context->organizationId();

            $current = $model->getAttribute('organization_id');

            if (! is_string($current) && ! $current instanceof \Stringable) {
                $current = null;
            }

            if ($current === null) {
                $model->setAttribute('organization_id', $organizationId);

                return;
            }

            if (! hash_equals($organizationId, (string) $current)) {
                throw new MissingTenantContextException(
                    'Model organization_id contradicts the established tenant context.'
                );
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
