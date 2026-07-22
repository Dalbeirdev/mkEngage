<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes tenant context for API requests and wraps the request in a
 * transaction so PostgreSQL RLS sees it (ADR-007).
 *
 * Order matters: this runs BEFORE auth:sanctum. Sanctum's user lookup reads
 * the RLS-protected users table, so context must exist first. The bearer
 * token is resolved against personal_access_tokens — auth infrastructure,
 * deliberately not RLS-scoped — whose denormalized organization_id is what
 * establishes the context (see the PAT migration). The org claim is not
 * client-supplied data: it was written server-side at token issuance from a
 * verified login (RULES-tenant-isolation #4), and auth:sanctum still fully
 * verifies the token afterwards; a forged bearer gets context but no
 * authentication and dies with 401 inside the transaction.
 *
 * The per-request transaction is the transaction scope required by ADR-007
 * (`SET LOCAL` is applied by ApplyTenantContextToTransactions on BEGIN).
 * PostgreSQL statement_timeout / idle_in_transaction_session_timeout bound
 * runaway requests (configured in infrastructure/docker + production values).
 */
final class EstablishTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if ($bearer !== null) {
            $token = PersonalAccessToken::findToken($bearer);

            if ($token?->organization_id !== null) {
                $this->context->set((string) $token->organization_id);
            }
        }

        if (! $this->context->established()) {
            // No verifiable tenant ⇒ no tenant data. Unauthenticated routes
            // (health, login) are not behind this middleware; everything that
            // is fails closed here.
            return response()->json([
                'type' => 'about:blank',
                'title' => 'Unauthenticated',
                'status' => 401,
            ], 401, ['Content-Type' => 'application/problem+json']);
        }

        try {
            return DB::transaction(fn (): Response => $next($request));
        } finally {
            $this->context->clear();
        }
    }
}
