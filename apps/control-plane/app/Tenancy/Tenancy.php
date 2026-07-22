<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Support\Database\Rls;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Entry points for running code under a tenant (ADR-007).
 *
 * Tenant DB work belongs inside Tenancy::run(): it pins the TenantContext,
 * opens a transaction, and ApplyTenantContextToTransactions issues
 * `SET LOCAL app.current_org_id` at transaction start so PostgreSQL RLS sees
 * the tenant. SET LOCAL dies with the transaction — safe under PgBouncer
 * transaction pooling.
 */
final class Tenancy
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Execute $callback for the given organization inside a transaction with
     * RLS context applied. Restores the previous context afterwards (nested
     * platform jobs iterating organizations rely on this).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function run(string $organizationId, Closure $callback): mixed
    {
        $previous = $this->context->organizationIdOrNull();

        // Cross-tenant nesting inside an open transaction is forbidden:
        // `SET LOCAL` is transaction-scoped (not savepoint-scoped), so the
        // inner org's RLS context would leak into the outer scope on commit
        // of the savepoint — or fail closed the inner one. Either way it is a
        // programming error; platform code iterating organizations must run
        // each org OUTSIDE any surrounding transaction.
        if ($previous !== null
            && $previous !== $organizationId
            && DB::transactionLevel() > 0) {
            throw new MissingTenantContextException(
                'Nested cross-organization Tenancy::run() inside a transaction is not allowed.'
            );
        }

        $this->context->set($organizationId);

        try {
            return DB::transaction($callback);
        } finally {
            $previous === null ? $this->context->clear() : $this->context->set($previous);
        }
    }

    /**
     * Issue SET LOCAL for the current tenant on the given connection. Called
     * by ApplyTenantContextToTransactions inside every transaction; harmless
     * on non-PostgreSQL drivers.
     */
    public function applyToConnection(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $organizationId = $this->context->organizationIdOrNull();

        if ($organizationId === null) {
            return; // Unset context ⇒ RLS policies yield zero rows (fail closed).
        }

        $connection->unprepared(sprintf(
            "SET LOCAL %s = '%s'",
            Rls::contextSetting(),
            // UUID format enforced defensively; context values only ever come
            // from verified credentials (RULES-tenant-isolation #4).
            preg_match('/^[0-9a-f-]{36}$/i', $organizationId) === 1
                ? $organizationId
                : throw new MissingTenantContextException('Tenant context is not a valid UUID.'),
        ));
    }
}
