-- mkEngage local Postgres provisioning (mirrors production Terraform, ADR-010).
--
-- The application NEVER connects as a superuser: superusers bypass RLS
-- entirely, which would silently disable tenant isolation (ADR-007). The
-- RLS test suite asserts the connected role is non-superuser.

CREATE ROLE mkengage LOGIN PASSWORD 'mkengage' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;

CREATE DATABASE mkengage OWNER mkengage;
CREATE DATABASE mkengage_test OWNER mkengage;

-- pgvector must be created by a superuser, per database (ADR-006).
\c mkengage
CREATE EXTENSION IF NOT EXISTS vector;

-- Guardrails: bound runaway request transactions (EstablishTenantContext
-- wraps requests in a transaction; these caps are the safety net).
ALTER DATABASE mkengage SET statement_timeout = '30s';
ALTER DATABASE mkengage SET idle_in_transaction_session_timeout = '60s';

\c mkengage_test
CREATE EXTENSION IF NOT EXISTS vector;
