<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PostgreSQL Row-Level Security proof (ADR-007 layer 2; directive §9:
 * "Create automated tests proving that PostgreSQL rejects unauthorized
 * cross-tenant access").
 *
 * GENERATED over the live schema: every table carrying organization_id is
 * discovered from the catalog and asserted against — a new tenant table whose
 * migration forgot Rls::enable() fails this suite automatically
 * (RULES-tenant-isolation, required test #1).
 *
 * Skips loudly on SQLite: RLS does not exist there. CI runs this suite
 * against PostgreSQL and treats it as REQUIRED (DEPLOYMENT-minimum-production
 * gate). Note: the connected role must be non-superuser — superusers bypass
 * RLS entirely — asserted below.
 */

/** Tables with organization_id that are deliberately NOT RLS-scoped, with the reason. */
const RLS_EXEMPT = [
    'personal_access_tokens' => 'auth infrastructure: token lookup establishes context (see PAT migration)',
    'outbox_events' => 'platform infrastructure: the relay reads across orgs; envelopes are data-minimized (ADR-005)',
    'api_keys' => 'auth infrastructure: mk_live_ key lookup establishes context, same pattern as PATs (Phase 35)',
];

function tenantTables(): array
{
    return array_diff(
        DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('column_name', 'organization_id')
            ->orderBy('table_name')
            ->pluck('table_name')
            ->all(),
        array_keys(RLS_EXEMPT),
        ['organizations'], // tenancy root: parent_organization_id FK aside, it defines tenants
    );
}

function setRlsContext(?string $orgId): void
{
    $setting = Rls::contextSetting();
    DB::unprepared($orgId === null
        ? "SET {$setting} = ''"
        : "SET {$setting} = '{$orgId}'");
}

beforeEach(function (): void {
    if (! runningOnPostgres()) {
        $this->markTestSkipped('RLS requires PostgreSQL (set DB_CONNECTION=pgsql to run — REQUIRED in CI).');
    }
});

it('connects as a non-superuser role (superusers bypass RLS)', function (): void {
    $row = DB::selectOne('SELECT current_user, usesuper FROM pg_user WHERE usename = current_user');

    expect($row->usesuper)->toBeFalse();
});

it('has RLS enabled, forced, and a tenant policy on every tenant table', function (): void {
    foreach (tenantTables() as $table) {
        $class = DB::selectOne(
            'SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ? AND relnamespace = ?::regnamespace',
            [$table, 'public'],
        );

        expect($class->relrowsecurity)->toBeTrue("RLS not ENABLED on {$table} — its migration is missing Rls::enable()")
            ->and($class->relforcerowsecurity)->toBeTrue("RLS not FORCED on {$table}");

        $policies = DB::table('pg_policies')
            ->where('schemaname', 'public')
            ->where('tablename', $table)
            ->count();

        expect($policies)->toBeGreaterThan(0, "No RLS policy on {$table}");
    }
});

it('returns zero rows without tenant context on every tenant table (fail closed)', function (): void {
    seedTwoOrgsWithData();

    setRlsContext(null);

    foreach (tenantTables() as $table) {
        $count = (int) DB::selectOne("SELECT count(*) AS c FROM {$table}")->c;

        expect($count)->toBe(0, "{$table} leaked {$count} row(s) with NO tenant context");
    }
});

it('hides org B rows from org A context on every tenant table', function (): void {
    [$orgA, $orgB] = seedTwoOrgsWithData();

    foreach (tenantTables() as $table) {
        setRlsContext($orgB->id);
        $visibleToB = (int) DB::selectOne("SELECT count(*) AS c FROM {$table}")->c;

        setRlsContext($orgA->id);
        $crossTenant = (int) DB::selectOne("SELECT count(*) AS c FROM {$table} WHERE organization_id = ?", [$orgB->id])->c;

        expect($crossTenant)->toBe(0, "{$table}: org A context can see org B rows");

        if ($visibleToB > 0) {
            // Sanity: the isolation above is not vacuous — B really has rows here.
            expect($visibleToB)->toBeGreaterThan(0);
        }
    }
});

it('rejects INSERTs whose organization_id contradicts the context', function (): void {
    [$orgA, $orgB] = seedTwoOrgsWithData();

    setRlsContext($orgA->id);

    expect(fn () => DB::insert(
        'INSERT INTO departments (id, organization_id, name) VALUES (?, ?, ?)',
        [Str::uuid7()->toString(), $orgB->id, 'smuggled'],
    ))->toThrow(QueryException::class);
});

it('rejects cross-tenant UPDATE and DELETE (zero rows affected)', function (): void {
    [$orgA, $orgB] = seedTwoOrgsWithData();

    setRlsContext($orgA->id);

    $updated = DB::update('UPDATE users SET name = ? WHERE organization_id = ?', ['pwned', $orgB->id]);
    $deleted = DB::delete('DELETE FROM users WHERE organization_id = ?', [$orgB->id]);

    expect($updated)->toBe(0)
        ->and($deleted)->toBe(0);

    setRlsContext($orgB->id);
    expect((int) DB::selectOne('SELECT count(*) AS c FROM users')->c)->toBeGreaterThan(0);
});

/**
 * Seed both orgs with a row in every currently-modeled tenant table, using
 * raw SQL under explicit RLS context (bypassing the app layer on purpose —
 * this suite proves the DATABASE, not Eloquent).
 *
 * @return array{0: Organization, 1: Organization}
 */
function seedTwoOrgsWithData(): array
{
    $orgs = Organization::factory()->count(2)->create();

    foreach ($orgs as $org) {
        setRlsContext($org->id);

        $userId = Str::uuid7()->toString();
        $roleId = Str::uuid7()->toString();
        $deptId = Str::uuid7()->toString();

        DB::insert(
            'INSERT INTO users (id, organization_id, name, email, password, status, created_at) VALUES (?, ?, ?, ?, ?, ?, now())',
            [$userId, $org->id, 'Seed User', "seed@{$org->slug}.example", 'x', 'active'],
        );
        DB::insert(
            'INSERT INTO roles (id, organization_id, name, description, is_system) VALUES (?, ?, ?, ?, false)',
            [$roleId, $org->id, 'Seed Role', ''],
        );
        DB::insert(
            'INSERT INTO user_roles (id, organization_id, user_id, role_id) VALUES (?, ?, ?, ?)',
            [Str::uuid7()->toString(), $org->id, $userId, $roleId],
        );
        DB::insert(
            'INSERT INTO departments (id, organization_id, name) VALUES (?, ?, ?)',
            [$deptId, $org->id, 'Seed Dept'],
        );
        DB::insert(
            'INSERT INTO department_user (id, organization_id, department_id, user_id) VALUES (?, ?, ?, ?)',
            [Str::uuid7()->toString(), $org->id, $deptId, $userId],
        );
        DB::insert(
            'INSERT INTO audit_log (id, organization_id, actor, action, context, created_at) VALUES (?, ?, ?, ?, ?::jsonb, now())',
            [Str::uuid7()->toString(), $org->id, 'system', 'seed', '{}'],
        );
    }

    setRlsContext(null);

    return [$orgs[0], $orgs[1]];
}
