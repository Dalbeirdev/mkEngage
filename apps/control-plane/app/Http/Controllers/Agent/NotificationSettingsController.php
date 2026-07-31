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
 * Agent notification settings. Stored in organizations.settings.notifications;
 * currently one knob: email agents when a customer's message has waited more
 * than N minutes without a reply (consumed by notifications:missed).
 */
final class NotificationSettingsController extends Controller
{
    public function show(TenantContext $context): JsonResponse
    {
        return response()->json(self::contract($this->organization($context)));
    }

    public function update(Request $request, TenantContext $context): JsonResponse
    {
        $validated = $request->validate([
            'missed_email_enabled' => ['required', 'boolean'],
            'missed_after_minutes' => ['sometimes', 'integer', 'min:1', 'max:120'],
        ]);

        $organization = $this->organization($context);
        $settings = is_array($organization->settings) ? $organization->settings : [];
        $notifications = is_array($settings['notifications'] ?? null) ? $settings['notifications'] : [];

        $notifications['missed_email_enabled'] = (bool) $validated['missed_email_enabled'];
        if (array_key_exists('missed_after_minutes', $validated)) {
            $notifications['missed_after_minutes'] = (int) $validated['missed_after_minutes'];
        }

        $settings['notifications'] = $notifications;
        $organization->settings = $settings;
        $organization->save();

        $user = $request->user();
        AuditLogEntry::record(
            actor: $user instanceof User ? 'user:'.$user->id : 'system',
            action: 'notifications.updated',
            subject: $organization,
            ip: $request->ip(),
        );

        return response()->json(self::contract($organization));
    }

    /** @return array{missed_email_enabled: bool, missed_after_minutes: int} */
    public static function contract(Organization $organization): array
    {
        $settings = is_array($organization->settings) ? $organization->settings : [];
        $notifications = is_array($settings['notifications'] ?? null) ? $settings['notifications'] : [];
        $minutes = $notifications['missed_after_minutes'] ?? null;

        return [
            'missed_email_enabled' => ($notifications['missed_email_enabled'] ?? false) === true,
            'missed_after_minutes' => is_int($minutes) && $minutes >= 1 ? $minutes : 5,
        ];
    }

    private function organization(TenantContext $context): Organization
    {
        return Organization::query()->whereKey($context->organizationId())->firstOrFail();
    }
}
