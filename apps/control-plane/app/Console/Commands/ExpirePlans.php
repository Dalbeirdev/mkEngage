<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLogEntry;
use App\Models\Organization;
use App\Services\PlanService;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;

/**
 * Daily sweep: paid plans past their expiry drop to free, which also clears
 * the persisted white_label entitlement (reads already treat expired plans
 * as free via PlanService::effectivePlan — this reconciles storage).
 */
final class ExpirePlans extends Command
{
    protected $signature = 'plans:expire';

    protected $description = 'Downgrade organizations whose paid plan has expired.';

    public function handle(PlanService $plans, Tenancy $tenancy): int
    {
        $expired = Organization::query()
            ->where('plan', '!=', 'free')
            ->whereNotNull('plan_expires_at')
            ->where('plan_expires_at', '<', now())
            ->get();

        foreach ($expired as $organization) {
            $previous = $organization->plan;
            $plans->apply($organization, 'free', null);

            $tenancy->run($organization->id, function () use ($organization, $previous): void {
                AuditLogEntry::record(
                    actor: 'system',
                    action: 'billing.plan_expired',
                    subject: $organization,
                    context: ['previous_plan' => $previous],
                );
            });

            $this->info("{$organization->name}: {$previous} expired, now free");
        }

        return self::SUCCESS;
    }
}
