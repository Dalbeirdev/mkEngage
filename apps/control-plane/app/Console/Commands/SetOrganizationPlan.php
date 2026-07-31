<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLogEntry;
use App\Models\Organization;
use App\Services\PlanService;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;

/**
 * Operator plan activation (billing v1): the platform owner invoices the
 * customer however they like, then activates the plan here. Payment-provider
 * checkout can automate this call later.
 */
final class SetOrganizationPlan extends Command
{
    protected $signature = 'org:plan {slug : Organization slug} {plan : One of the keys in config/plans.php} {--months=12 : Paid-plan validity from today}';

    protected $description = 'Set an organization\'s billing plan (free plans never expire).';

    public function handle(PlanService $plans, Tenancy $tenancy): int
    {
        $slug = $this->argument('slug');
        $plan = $this->argument('plan');

        if (! is_array(config("plans.{$plan}"))) {
            $this->error("Unknown plan \"{$plan}\". Available: ".implode(', ', array_map('strval', array_keys((array) config('plans')))));

            return self::FAILURE;
        }

        $organization = Organization::query()->where('slug', $slug)->first();
        if ($organization === null) {
            $this->error("No organization with slug \"{$slug}\".");

            return self::FAILURE;
        }

        $months = max(1, (int) $this->option('months'));
        $expiresAt = $plan === 'free' ? null : now()->addMonths($months);

        $plans->apply($organization, $plan, $expiresAt);

        // Audit entries are tenant-scoped — record under the org's context.
        $tenancy->run($organization->id, function () use ($organization, $plan, $expiresAt): void {
            AuditLogEntry::record(
                actor: 'system',
                action: 'billing.plan_set',
                subject: $organization,
                context: ['plan' => $plan, 'expires_at' => $expiresAt?->toIso8601String()],
            );
        });

        $this->info("{$organization->name}: plan set to {$plan}".($expiresAt !== null ? " until {$expiresAt->toDateString()}" : ''));

        return self::SUCCESS;
    }
}
