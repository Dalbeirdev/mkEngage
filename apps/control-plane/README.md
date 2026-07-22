# mkEngage Control Plane

Laravel SaaS control plane (directive §2, ADR-001): authentication, tenancy, users/roles/departments, and — in later phases — billing, configuration, and administration APIs.

## Local development (Windows)

- PHP 8.4: `C:\tools\php84\php.exe` (standalone; XAMPP's 8.2 is too old for the toolchain)
- Composer: `C:\xampp\composer.phar`
- Infra: `docker compose -f ../../infrastructure/docker/docker-compose.yml up -d` (Postgres+pgvector as non-superuser role `mkengage`, Redis, Mailpit)

```bash
C:/tools/php84/php.exe C:/xampp/composer.phar install
cp .env.example .env && C:/tools/php84/php.exe artisan key:generate
C:/tools/php84/php.exe artisan migrate
C:/tools/php84/php.exe artisan serve
```

`composer.json` fakes `ext-pcntl`/`ext-posix` (Horizon) — they exist only in the Linux production image.

## Quality gates

```bash
C:/tools/php84/php.exe vendor/bin/pint          # style
C:/tools/php84/php.exe vendor/bin/phpstan       # level 9 + Larastan
C:/tools/php84/php.exe vendor/bin/pest          # tests
```

The RLS isolation suite ([tests/Feature/Tenancy/RlsIsolationTest.php](tests/Feature/Tenancy/RlsIsolationTest.php)) requires PostgreSQL (`DB_CONNECTION=pgsql`, database `mkengage_test`, user `mkengage`) and is REQUIRED in CI — it skips (loudly) on SQLite.

## Tenancy architecture (read before touching tenant data)

Two independent layers (ADR-007, RULES-tenant-isolation):

1. **Application scope** — every tenant model uses `BelongsToOrganization`; queries without an established `TenantContext` throw.
2. **PostgreSQL RLS** — every tenant table is `FORCE ROW LEVEL SECURITY` with policies keyed to `app.current_org_id`, set via `SET LOCAL` on every transaction begin (`ApplyTenantContextToTransactions`).

Rules of thumb:
- Tenant DB work runs inside `Tenancy::run($orgId, fn)` (jobs, commands) or behind `EstablishTenantContext` middleware (HTTP).
- New tenant table ⇒ `organization_id` + `Rls::enable('table')` in the same migration. The generated RLS suite fails otherwise.
- Never connect as a superuser (bypasses RLS — asserted by the test suite).
