<?php

declare(strict_types=1);

namespace App\Tenancy;

/**
 * Holds the current tenant (organization) for the lifetime of one request or
 * job. Registered as a SCOPED singleton (reset between Octane requests —
 * ADR-007 "Octane-safe"). Layer 1 of the two-layer isolation model; layer 2
 * is PostgreSQL RLS fed by ApplyTenantContextToTransactions.
 */
final class TenantContext
{
    private ?string $organizationId = null;

    public function set(string $organizationId): void
    {
        $this->organizationId = $organizationId;
    }

    public function clear(): void
    {
        $this->organizationId = null;
    }

    public function established(): bool
    {
        return $this->organizationId !== null;
    }

    public function organizationId(): string
    {
        if ($this->organizationId === null) {
            // Fail closed (RULES-tenant-isolation): touching tenant data
            // without established context is a programming error, never a
            // silent full-table scan.
            throw new MissingTenantContextException(
                'Tenant context has not been established for this request/job.'
            );
        }

        return $this->organizationId;
    }

    public function organizationIdOrNull(): ?string
    {
        return $this->organizationId;
    }
}
