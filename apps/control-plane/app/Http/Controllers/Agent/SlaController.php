<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * First-response SLA targets (minutes) per priority. Stored in
 * organizations.settings.sla; a null/absent target means no SLA for that
 * priority.
 */
final class SlaController extends Controller
{
    private const PRIORITIES = ['urgent', 'high', 'normal', 'low'];

    public function show(TenantContext $context): JsonResponse
    {
        return response()->json(self::contract($this->organization($context)));
    }

    public function update(Request $request, TenantContext $context): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'targets' => ['sometimes', 'array:urgent,high,normal,low'],
            'targets.urgent' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10080'],
            'targets.high' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10080'],
            'targets.normal' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10080'],
            'targets.low' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10080'],
        ]);

        $organization = $this->organization($context);
        $settings = is_array($organization->settings) ? $organization->settings : [];
        $sla = is_array($settings['sla'] ?? null) ? $settings['sla'] : [];

        $sla['enabled'] = (bool) $validated['enabled'];
        if (array_key_exists('targets', $validated)) {
            $targets = [];
            foreach (self::PRIORITIES as $priority) {
                $value = $validated['targets'][$priority] ?? null;
                $targets[$priority] = is_int($value) ? $value : null;
            }
            $sla['targets'] = $targets;
        }

        $settings['sla'] = $sla;
        $organization->settings = $settings;
        $organization->save();

        $user = $request->user();
        AuditLogEntry::record(
            actor: $user instanceof User ? 'user:'.$user->id : 'system',
            action: 'sla.updated',
            subject: $organization,
            ip: $request->ip(),
        );

        return $this->show($context);
    }

    /**
     * @return array{enabled: bool, targets: array<string, int|null>}
     */
    public static function contract(Organization $organization): array
    {
        $settings = is_array($organization->settings) ? $organization->settings : [];
        $sla = is_array($settings['sla'] ?? null) ? $settings['sla'] : [];
        $stored = is_array($sla['targets'] ?? null) ? $sla['targets'] : [];

        $targets = [];
        foreach (self::PRIORITIES as $priority) {
            $value = $stored[$priority] ?? null;
            $targets[$priority] = is_int($value) && $value >= 1 ? $value : null;
        }

        return [
            'enabled' => ($sla['enabled'] ?? false) === true,
            'targets' => $targets,
        ];
    }

    private function organization(TenantContext $context): Organization
    {
        return Organization::query()->whereKey($context->organizationId())->firstOrFail();
    }
}
