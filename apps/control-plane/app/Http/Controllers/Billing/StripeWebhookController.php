<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\Organization;
use App\Services\PlanService;
use App\Tenancy\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Stripe webhook: activates/extends/downgrades plans from subscription
 * lifecycle events. Signature-verified (Stripe-Signature t/v1 HMAC scheme,
 * 5-minute tolerance); unknown events are acknowledged and ignored.
 *
 *   checkout.session.completed → activate plan, remember customer+subscription
 *   invoice.paid               → extend expiry to the paid period end + grace
 *   customer.subscription.deleted → downgrade to free
 */
final class StripeWebhookController extends Controller
{
    private const SIGNATURE_TOLERANCE_SECONDS = 300;

    private const EXPIRY_GRACE_DAYS = 3;

    public function __invoke(Request $request, PlanService $plans, Tenancy $tenancy): JsonResponse
    {
        $secret = config('services.stripe.webhook_secret');
        abort_unless(is_string($secret) && $secret !== '', 404);

        $payload = $request->getContent();
        abort_unless(
            $this->signatureValid($payload, $request->header('Stripe-Signature'), $secret),
            400,
            'Invalid signature.',
        );

        $event = json_decode($payload, true);
        if (! is_array($event)) {
            return response()->json(['status' => 'ignored']);
        }

        $type = is_string($event['type'] ?? null) ? $event['type'] : '';
        $object = is_array($event['data'] ?? null) && is_array($event['data']['object'] ?? null)
            ? $event['data']['object']
            : [];

        match ($type) {
            'checkout.session.completed' => $this->activate($object, $plans, $tenancy),
            'invoice.paid' => $this->extend($object, $plans, $tenancy),
            'customer.subscription.deleted' => $this->cancel($object, $plans, $tenancy),
            default => null,
        };

        return response()->json(['status' => 'ok']);
    }

    /** @param array<mixed> $object */
    private function activate(array $object, PlanService $plans, Tenancy $tenancy): void
    {
        $orgId = $object['client_reference_id'] ?? null;
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $plan = is_string($metadata['plan'] ?? null) ? $metadata['plan'] : null;

        if (! is_string($orgId) || $plan === null || ! is_array(config("plans.{$plan}"))) {
            return;
        }

        $organization = Organization::query()->whereKey($orgId)->first();
        if ($organization === null) {
            return;
        }

        $customer = $object['customer'] ?? null;
        $subscription = $object['subscription'] ?? null;
        $organization->stripe_customer_id = is_string($customer) ? $customer : null;
        $organization->stripe_subscription_id = is_string($subscription) ? $subscription : null;

        // invoice.paid follows with the exact period; this expiry only has to
        // bridge the gap (and covers webhook ordering hiccups).
        $plans->apply($organization, $plan, now()->addMonth()->addDays(self::EXPIRY_GRACE_DAYS));

        $this->audit($tenancy, $organization, 'billing.stripe_checkout_completed', ['plan' => $plan]);
    }

    /** @param array<mixed> $object */
    private function extend(array $object, PlanService $plans, Tenancy $tenancy): void
    {
        $organization = $this->organizationFor($object);
        if ($organization === null) {
            return;
        }

        // Subscription invoices carry the paid period on their line items.
        $periodEnd = null;
        $lines = is_array($object['lines'] ?? null) && is_array($object['lines']['data'] ?? null)
            ? $object['lines']['data']
            : [];
        foreach ($lines as $line) {
            $period = is_array($line) && is_array($line['period'] ?? null) ? $line['period'] : [];
            if (is_int($period['end'] ?? null)) {
                $periodEnd = max($periodEnd ?? 0, $period['end']);
            }
        }

        $expiresAt = $periodEnd !== null
            ? Carbon::createFromTimestamp($periodEnd)->addDays(self::EXPIRY_GRACE_DAYS)
            : now()->addMonth()->addDays(self::EXPIRY_GRACE_DAYS);

        $subscriptionMetadata = is_array($object['subscription_details'] ?? null)
            && is_array($object['subscription_details']['metadata'] ?? null)
            ? $object['subscription_details']['metadata']
            : [];
        $plan = is_string($subscriptionMetadata['plan'] ?? null)
            ? $subscriptionMetadata['plan']
            : $organization->plan;
        if (! is_array(config("plans.{$plan}")) || $plan === 'free') {
            return;
        }

        $plans->apply($organization, $plan, $expiresAt);

        $this->audit($tenancy, $organization, 'billing.stripe_invoice_paid', [
            'plan' => $plan,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    /** @param array<mixed> $object */
    private function cancel(array $object, PlanService $plans, Tenancy $tenancy): void
    {
        $subscriptionId = $object['id'] ?? null;
        if (! is_string($subscriptionId)) {
            return;
        }

        $organization = Organization::query()->where('stripe_subscription_id', $subscriptionId)->first();
        if ($organization === null) {
            return;
        }

        $plans->apply($organization, 'free', null);
        $organization->stripe_subscription_id = null;
        $organization->save();

        $this->audit($tenancy, $organization, 'billing.stripe_subscription_cancelled', []);
    }

    /** @param array<mixed> $object */
    private function organizationFor(array $object): ?Organization
    {
        $subscription = $object['subscription'] ?? null;
        if (is_string($subscription)) {
            $match = Organization::query()->where('stripe_subscription_id', $subscription)->first();
            if ($match !== null) {
                return $match;
            }
        }

        $customer = $object['customer'] ?? null;

        return is_string($customer)
            ? Organization::query()->where('stripe_customer_id', $customer)->first()
            : null;
    }

    /** @param array<string, mixed> $context */
    private function audit(Tenancy $tenancy, Organization $organization, string $action, array $context): void
    {
        $tenancy->run($organization->id, function () use ($organization, $action, $context): void {
            AuditLogEntry::record(
                actor: 'system',
                action: $action,
                subject: $organization,
                context: $context,
            );
        });
    }

    private function signatureValid(string $payload, ?string $header, string $secret): bool
    {
        if (! is_string($header) || $header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if (! is_numeric($timestamp) || abs(time() - (int) $timestamp) > self::SIGNATURE_TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
