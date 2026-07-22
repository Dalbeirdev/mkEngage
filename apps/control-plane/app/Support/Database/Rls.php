<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL Row-Level Security enforcement (ADR-007, RULES-tenant-isolation).
 *
 * Every migration that creates a tenant-owned table (any table with an
 * organization_id column) MUST call Rls::enable('table') in the same
 * migration. The generated RLS test suite fails on any tenant table where
 * this was forgotten.
 *
 * The policy fails closed: when app.current_org_id is unset (or empty),
 * NULLIF(...)::uuid yields NULL, the comparison is NULL, and zero rows are
 * visible. FORCE makes policies apply to the table owner as well — the
 * application must connect as a non-superuser role (superusers always bypass
 * RLS; the local/dev init script and production provisioning both create the
 * non-superuser app role).
 */
final class Rls
{
    private const CONTEXT_SETTING = 'app.current_org_id';

    /**
     * Enable and force RLS with tenant policies on a table. No-op on non-PostgreSQL
     * drivers (the local SQLite feature-test path); PostgreSQL is the only
     * supported production driver (ADR-006).
     */
    public static function enable(string $table): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $setting = self::CONTEXT_SETTING;

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement(<<<SQL
            CREATE POLICY {$table}_tenant_isolation ON {$table}
            USING (organization_id = NULLIF(current_setting('{$setting}', true), '')::uuid)
            WITH CHECK (organization_id = NULLIF(current_setting('{$setting}', true), '')::uuid)
        SQL);
    }

    public static function disable(string $table): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
    }

    public static function contextSetting(): string
    {
        return self::CONTEXT_SETTING;
    }
}
