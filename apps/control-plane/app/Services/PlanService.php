<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Channel;
use App\Models\Chatbot;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Plan entitlements (billing v1). The plan matrix lives in config/plans.php;
 * activation is operator-driven (`php artisan org:plan`) so payment
 * collection can layer on later without touching enforcement. Usage counts
 * run under the caller's tenant context.
 */
final class PlanService
{
    /** The org's plan for entitlement purposes — expired paid plans read as free. */
    public function effectivePlan(Organization $organization): string
    {
        $plan = $organization->plan;

        if ($plan !== 'free'
            && $organization->plan_expires_at !== null
            && $organization->plan_expires_at->isPast()) {
            return 'free';
        }

        return is_array(config("plans.{$plan}")) ? $plan : 'free';
    }

    /** @return array{label: string, price: string, max_channels: int|null, max_chatbots: int|null, max_agents: int|null, white_label: bool} */
    public function limits(string $plan): array
    {
        $config = config("plans.{$plan}");
        $config = is_array($config) ? $config : [];
        $int = fn (mixed $value): ?int => is_int($value) ? $value : null;

        return [
            'label' => is_string($config['label'] ?? null) ? $config['label'] : ucfirst($plan),
            'price' => is_string($config['price'] ?? null) ? $config['price'] : '',
            'max_channels' => $int($config['max_channels'] ?? null),
            'max_chatbots' => $int($config['max_chatbots'] ?? null),
            'max_agents' => $int($config['max_agents'] ?? null),
            'white_label' => ($config['white_label'] ?? false) === true,
        ];
    }

    /** Gate a resource creation: 422 with an upgrade hint when the plan is at its limit. */
    public function assertCanCreate(Organization $organization, string $resource): void
    {
        $limits = $this->limits($this->effectivePlan($organization));

        [$max, $count] = match ($resource) {
            'channels' => [$limits['max_channels'], Channel::query()->count()],
            'chatbots' => [$limits['max_chatbots'], Chatbot::query()->count()],
            'agents' => [$limits['max_agents'], User::query()->where('status', 'active')->count()],
            default => [null, 0],
        };

        if ($max !== null && $count >= $max) {
            throw ValidationException::withMessages([
                'plan' => ["Your {$limits['label']} plan allows {$max} {$resource}. Upgrade to add more."],
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function contract(Organization $organization): array
    {
        $plan = $this->effectivePlan($organization);
        $limits = $this->limits($plan);

        return [
            'plan' => $plan,
            'label' => $limits['label'],
            'price' => $limits['price'],
            'expires_at' => $organization->plan_expires_at?->toIso8601String(),
            'white_label' => $limits['white_label'],
            'limits' => [
                'channels' => $limits['max_channels'],
                'chatbots' => $limits['max_chatbots'],
                'agents' => $limits['max_agents'],
            ],
            'usage' => [
                'channels' => Channel::query()->count(),
                'chatbots' => Chatbot::query()->count(),
                'agents' => User::query()->where('status', 'active')->count(),
            ],
        ];
    }

    /** Persist a plan choice and keep the white_label entitlement flag in sync. */
    public function apply(Organization $organization, string $plan, ?Carbon $expiresAt): void
    {
        $organization->plan = $plan;
        $organization->plan_expires_at = $expiresAt;
        $organization->white_label = $this->limits($plan)['white_label'];
        $organization->save();
    }
}
