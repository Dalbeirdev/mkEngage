# mkEngage — Test Execution Log

Build `c911bcb` · 2026-07-24. Every command recorded; secrets masked. Test data created in the **non-production** dev DB (orgs `acme`=Alpha, `beta-qa`=Beta; agents `agent-alpha@qa.test` / `agent-beta@qa.test`, password masked).

## Environment validation
```
ports: PG:5434=UP NATS:4222=UP API:8000=UP GW:4000=UP AI:8100=UP DASH:3100=UP WIDGET:5174=UP
GET http://127.0.0.1:8000/up          -> 200
GET http://127.0.0.1:8100/health      -> 200
GET http://127.0.0.1:4000/health      -> 404   (DEFECT K1 — no gateway health endpoint)
```

## Static / unit / feature suites
```
# Control plane — full Pest on PostgreSQL (RLS as non-superuser, NATS relay live)
DB_CONNECTION=pgsql DB_PORT=5434 DB_DATABASE=mkengage_test NATS_URL=... vendor/bin/pest
  => 88 passed (497 assertions)          [EXEC-PASS]
vendor/bin/pest tests/Feature/Tenancy    => 14 passed (102 assertions)  [tenant isolation]
vendor/bin/phpstan analyse --memory-limit=1G  => No errors
vendor/bin/pint --test                   => passed
composer validate                        => valid

# Realtime gateway — ExUnit with DB + NATS
GATEWAY_DB_TESTS=1 NATS_URL=... mix test  => 22 passed
mix format --check-formatted             => clean
mix compile --warnings-as-errors         => clean

# AI service
python -m pytest -q                      => 15 passed
python -m ruff check app                 => All checks passed
python -m mypy app                       => BLOCKED (module not installed)

# Widget
tsc --noEmit                             => exit 0
playwright test                          => 8 passed (incl. axe accessibility)

# Dashboard
tsc --noEmit / eslint src                => clean
vitest run                               => 14 passed
playwright test                          => 3 passed (incl. axe light+dark)

# Contracts / security scanners
redocly lint control-plane.v1.yaml       => valid
Gitleaks + Semgrep + Trivy               => PASS in CI run 30089889943 (c911bcb, green)
```

## Live security probe battery (curl vs running services)
```
PROBE A — Authentication
  GET /api/user (no token)                        -> 401  PASS
  GET /api/conversations (no token)               -> 401  PASS
  POST /api/widget/conversations (garbage bearer) -> 401  PASS
  POST /api/auth/token (wrong password)           -> 422  PASS
  POST /api/auth/token x12 rapid                  -> 422x5 then 429x7  PASS (rate limited)

PROBE B — Cross-tenant IDOR (Alpha data, Beta token)
  Beta agent GET /api/conversations/{alpha}       -> 404  PASS (no oracle)
  Beta agent GET /api/conversations/{alpha}/messages -> 404 PASS
  Alpha agent GET own conversation (control)      -> 200  PASS

PROBE C — Privilege escalation (visitor token)
  visitor -> /api/user                            -> 403  PASS
  visitor -> /api/conversations                   -> 403  PASS

PROBE D — Attachment IDOR (real attachment id 019f941f-0b3f-...)
  Owner download own clean attachment             -> 200  PASS (control)
  Visitor B download A's attachment via own conv  -> 404  PASS
  Visitor B via A's conv id (not owned)           -> 404  PASS
  Beta agent download Alpha attachment            -> 404  PASS (cross-tenant)
  Signed URL contains signature=; tampered sig    -> 403  PASS

PROBE E — Error leakage
  POST /api/auth/token (malformed JSON)           -> 422, body = validation message only,
                                                     no stack trace / SQLSTATE / file path  PASS

PROBE — AI service auth
  POST /v1/reply (no token)                        -> 401  PASS
  POST /v1/reply (wrong token)                     -> 401  PASS
  POST /v1/embed (wrong token)                     -> 401  PASS
  GET  /health                                     -> 200  PASS
```

## Test data setup command (masked)
```
php artisan tinker --execute="...Organization::factory()->create(['slug'=>'beta-qa'])...
  User::factory()->create(['email'=>'agent-*@qa.test','password'=>Hash::make('***')])..."
=> ALPHA=acme|sk_demo_acme_2026   BETA=beta-qa|sk_znpwanzxurz43lb5hgjk80uh
```

## Not executed (reason logged)
- Real-model AI eval / prompt injection — no provider credentials (fake provider only).
- Firefox/WebKit — Playwright configs are chromium-only.
- k6 performance, backup/restore — no infrastructure in repo.
