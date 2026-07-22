<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Database\Events\TransactionBeginning;

/**
 * Listener: every transaction that begins while a tenant context is
 * established gets `SET LOCAL app.current_org_id` as its first statement.
 * This makes RLS context automatic for all code inside DB::transaction() /
 * Tenancy::run() — feature code cannot forget it (ADR-007 layer 2).
 */
final class ApplyTenantContextToTransactions
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(TransactionBeginning $event): void
    {
        // Applied on every BEGIN including savepoints: savepoint levels
        // normally inherit the outer SET LOCAL, but under test harnesses
        // (RefreshDatabase) the outermost transaction begins before any
        // tenant context exists, so each Tenancy::run savepoint must set it.
        // PostgreSQL reverts SET LOCAL on savepoint rollback, so this cannot
        // leak a stale tenant into the outer scope.
        $this->tenancy->applyToConnection($event->connection);
    }
}
