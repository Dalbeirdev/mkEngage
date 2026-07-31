<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\PlanService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * Read-only billing surface: the org's effective plan, limits, usage, and
 * the full plan catalog for the comparison UI. Plan changes are operator
 * actions (org:plan) — never a tenant-side write.
 */
final class BillingController extends Controller
{
    public function show(TenantContext $context, PlanService $plans): JsonResponse
    {
        $organization = Organization::query()->whereKey($context->organizationId())->firstOrFail();

        $catalog = [];
        foreach (array_keys((array) config('plans')) as $key) {
            if (is_string($key)) {
                $catalog[$key] = $plans->limits($key);
            }
        }

        return response()->json([
            ...$plans->contract($organization),
            'catalog' => $catalog,
        ]);
    }
}
