<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Machine API key auth (Phase 35, §15): `Authorization: Bearer mk_live_…`.
 * Mirrors EstablishTenantContext's shape — resolve the key (auth infra, not
 * RLS-scoped), establish tenant context from its denormalized org id, wrap
 * the request in the RLS transaction. Revoked/unknown keys fail closed 401
 * with the same problem shape (no key oracle).
 */
final class AuthenticateApiKey
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        $key = $bearer !== null && str_starts_with($bearer, 'mk_')
            ? ApiKey::findActiveByPlaintext($bearer)
            : null;

        if ($key === null) {
            return response()->json([
                'type' => 'about:blank',
                'title' => 'Unauthenticated',
                'status' => 401,
            ], 401, ['Content-Type' => 'application/problem+json']);
        }

        $key->forceFill(['last_used_at' => now()])->save();
        $this->context->set((string) $key->organization_id);

        try {
            return DB::transaction(fn (): Response => $next($request));
        } finally {
            $this->context->clear();
        }
    }
}
