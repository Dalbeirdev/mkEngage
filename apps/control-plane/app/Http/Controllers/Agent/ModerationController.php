<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\ModerationIpBan;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Moderation settings (§ abuse controls): the org's IP ban list plus the
 * profanity filter config. Bans are refused at widget-session creation; the
 * profanity terms mask visitor message text at ingest. Profanity config lives
 * in organizations.settings (json) like the other widget settings; bans are a
 * queryable, RLS-scoped table.
 */
final class ModerationController extends Controller
{
    private const MAX_TERMS = 200;

    public function show(TenantContext $context): JsonResponse
    {
        $organization = $this->organization($context);

        return response()->json([
            'profanity' => $this->profanityContract($organization),
            'ip_bans' => ModerationIpBan::query()
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (ModerationIpBan $ban): array => $ban->toContract())
                ->all(),
        ]);
    }

    /** Update the profanity filter config (enabled, mask char, term list). */
    public function update(Request $request, TenantContext $context): JsonResponse
    {
        $validated = $request->validate([
            'profanity' => ['required', 'array:enabled,mask_char,terms'],
            'profanity.enabled' => ['required', 'boolean'],
            'profanity.mask_char' => ['sometimes', 'string', 'size:1'],
            'profanity.terms' => ['sometimes', 'array', 'max:'.self::MAX_TERMS],
            'profanity.terms.*' => ['string', 'max:100'],
        ]);

        $organization = $this->organization($context);
        $settings = is_array($organization->settings) ? $organization->settings : [];
        $moderation = is_array($settings['moderation'] ?? null) ? $settings['moderation'] : [];

        /** @var list<string> $terms */
        $terms = array_values(array_unique(array_filter(
            array_map(
                static fn (string $t): string => mb_strtolower(trim($t)),
                $validated['profanity']['terms'] ?? [],
            ),
            static fn (string $t): bool => $t !== '',
        )));

        $moderation['profanity'] = [
            'enabled' => (bool) $validated['profanity']['enabled'],
            'mask_char' => $validated['profanity']['mask_char'] ?? '*',
            'terms' => $terms,
        ];
        $settings['moderation'] = $moderation;

        $organization->settings = $settings;
        $organization->save();

        $this->audit($request, 'moderation.profanity.updated', $organization);

        return $this->show($context);
    }

    /** Ban an IP for this org. Idempotent on (org, ip): re-banning updates the reason. */
    public function storeBan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ip_address' => ['required', 'string', 'ip'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();

        $ban = ModerationIpBan::query()->updateOrCreate(
            ['ip_address' => $validated['ip_address']],
            [
                'reason' => $validated['reason'] ?? null,
                'created_by' => $user instanceof User ? $user->id : null,
            ],
        );

        $this->audit($request, 'moderation.ip_banned', $ban);

        return response()->json($ban->toContract(), 201);
    }

    /**
     * Lift a ban. Resolved in the controller body (not via implicit binding)
     * so the org global scope runs with tenant context already established;
     * the scope makes cross-tenant ids 404.
     */
    public function destroyBan(Request $request, string $ipBan): JsonResponse
    {
        $ban = ModerationIpBan::query()->whereKey($ipBan)->firstOrFail();
        $ban->delete();

        $this->audit($request, 'moderation.ip_unbanned', $ban);

        return response()->json(null, 204);
    }

    /** @return array{enabled: bool, mask_char: string, terms: list<string>} */
    private function profanityContract(Organization $organization): array
    {
        $settings = is_array($organization->settings) ? $organization->settings : [];
        $moderation = is_array($settings['moderation'] ?? null) ? $settings['moderation'] : [];
        $profanity = is_array($moderation['profanity'] ?? null) ? $moderation['profanity'] : [];
        $terms = is_array($profanity['terms'] ?? null) ? $profanity['terms'] : [];

        return [
            'enabled' => ($profanity['enabled'] ?? false) === true,
            'mask_char' => is_string($profanity['mask_char'] ?? null) && $profanity['mask_char'] !== ''
                ? $profanity['mask_char']
                : '*',
            'terms' => array_values(array_filter(
                array_map(static fn (mixed $t): string => is_string($t) ? $t : '', $terms),
                static fn (string $t): bool => $t !== '',
            )),
        ];
    }

    private function audit(Request $request, string $action, Organization|ModerationIpBan $subject): void
    {
        $user = $request->user();
        AuditLogEntry::record(
            actor: $user instanceof User ? 'user:'.$user->id : 'system',
            action: $action,
            subject: $subject,
            ip: $request->ip(),
        );
    }

    private function organization(TenantContext $context): Organization
    {
        return Organization::query()->whereKey($context->organizationId())->firstOrFail();
    }
}
