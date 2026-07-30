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
            'auto_close' => $this->autoCloseContract($organization),
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
            // Auto-close on repeat abuse: N masked messages → close + spam.
            'auto_close' => ['sometimes', 'array:enabled,threshold'],
            'auto_close.enabled' => ['required_with:auto_close', 'boolean'],
            'auto_close.threshold' => ['sometimes', 'integer', 'min:1', 'max:20'],
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

        if (array_key_exists('auto_close', $validated)) {
            $moderation['auto_close'] = [
                'enabled' => (bool) ($validated['auto_close']['enabled'] ?? false),
                'threshold' => (int) ($validated['auto_close']['threshold'] ?? 3),
            ];
        }

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
            // A single IP or a CIDR range (e.g. 203.0.113.0/24, 2001:db8::/32).
            'ip_address' => ['required', 'string', 'max:45', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! self::isIpOrCidr($value)) {
                    $fail('Enter a valid IP address or CIDR range.');
                }
            }],
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

    /** Accepts a bare IPv4/IPv6 address or a CIDR range with a valid prefix. */
    private static function isIpOrCidr(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        [$subnet, $bits] = array_pad(explode('/', $value, 2), 2, null);
        if (! is_string($subnet) || ! is_string($bits) || ! ctype_digit($bits)) {
            return false;
        }
        $packed = @inet_pton($subnet);
        if ($packed === false) {
            return false;
        }

        return (int) $bits <= strlen($packed) * 8;
    }

    /** @return array{enabled: bool, threshold: int} */
    private function autoCloseContract(Organization $organization): array
    {
        $settings = is_array($organization->settings) ? $organization->settings : [];
        $moderation = is_array($settings['moderation'] ?? null) ? $settings['moderation'] : [];
        $autoClose = is_array($moderation['auto_close'] ?? null) ? $moderation['auto_close'] : [];
        $threshold = $autoClose['threshold'] ?? null;

        return [
            'enabled' => ($autoClose['enabled'] ?? false) === true,
            'threshold' => is_int($threshold) && $threshold >= 1 ? $threshold : 3,
        ];
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
