<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\PlanService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Billing surface: the org's effective plan, limits, usage, and the plan
 * catalog — plus Stripe subscription checkout when keys are configured.
 * Without Stripe, plan changes stay operator actions (org:plan).
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
            'checkout_enabled' => $this->checkoutPlans() !== [],
            'checkout_plans' => $this->checkoutPlans(),
        ]);
    }

    /** Create a Stripe Checkout session for a paid plan; returns its URL. */
    public function checkout(Request $request, TenantContext $context): JsonResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'string', 'in:pro,business'],
        ]);
        $plan = $validated['plan'];

        $secret = config('services.stripe.secret');
        $priceId = config("services.stripe.prices.{$plan}");
        abort_unless(
            is_string($secret) && $secret !== '' && is_string($priceId) && $priceId !== '',
            409,
            'Stripe checkout is not configured on this server.',
        );

        $organization = Organization::query()->whereKey($context->organizationId())->firstOrFail();

        $base = config('app.dashboard_url');
        $base = is_string($base) && $base !== '' ? rtrim($base, '/') : '';

        $response = Http::withToken($secret)->asForm()->post('https://api.stripe.com/v1/checkout/sessions', [
            'mode' => 'subscription',
            'client_reference_id' => $organization->id,
            'line_items[0][price]' => $priceId,
            'line_items[0][quantity]' => 1,
            'success_url' => $base.'/settings/billing?checkout=success',
            'cancel_url' => $base.'/settings/billing?checkout=cancelled',
            'metadata[plan]' => $plan,
            'subscription_data[metadata][plan]' => $plan,
        ]);

        abort_unless($response->successful(), 502, 'Stripe could not create the checkout session.');

        $url = $response->json('url');
        abort_unless(is_string($url) && $url !== '', 502, 'Stripe returned no checkout URL.');

        return response()->json(['url' => $url], 201);
    }

    /** @return list<string> paid plans that have a configured Stripe price */
    private function checkoutPlans(): array
    {
        $secret = config('services.stripe.secret');
        if (! is_string($secret) || $secret === '') {
            return [];
        }

        $plans = [];
        foreach ((array) config('services.stripe.prices') as $plan => $priceId) {
            if (is_string($plan) && is_string($priceId) && $priceId !== '') {
                $plans[] = $plan;
            }
        }

        return $plans;
    }
}
