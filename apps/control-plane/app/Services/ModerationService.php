<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ModerationIpBan;
use App\Models\Organization;
use App\Tenancy\TenantContext;

/**
 * Moderation controls (competitor parity: IP ban + profanity filter).
 *
 * Both checks run inside the caller's tenant context — the IP ban list is
 * org-scoped by the global scope (fail closed), and the profanity config is
 * read from organizations.settings. The per-request org-settings load is
 * memoized so masking every message in a burst costs one query.
 */
final class ModerationService
{
    /** @var array{enabled: bool, mask_char: string, terms: list<string>}|null */
    private ?array $profanityCache = null;

    public function __construct(private readonly TenantContext $context) {}

    /** True when this IP is on the current org's ban list (RLS-scoped). */
    public function isIpBanned(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        return ModerationIpBan::query()->where('ip_address', $ip)->exists();
    }

    /**
     * Replace each configured profanity term (whole word, case-insensitive)
     * with a run of the mask character. A no-op when disabled or no terms —
     * the original body passes through untouched.
     */
    public function maskProfanity(string $body): string
    {
        $config = $this->profanityConfig();
        if ($config['enabled'] !== true || $config['terms'] === []) {
            return $body;
        }

        $mask = $config['mask_char'];
        foreach ($config['terms'] as $term) {
            if ($term === '') {
                continue;
            }
            $pattern = '/\b'.preg_quote($term, '/').'\b/iu';
            $body = preg_replace_callback(
                $pattern,
                static fn (array $match): string => str_repeat($mask, max(1, mb_strlen($match[0]))),
                $body,
            ) ?? $body;
        }

        return $body;
    }

    /** @return array{enabled: bool, mask_char: string, terms: list<string>} */
    private function profanityConfig(): array
    {
        if ($this->profanityCache !== null) {
            return $this->profanityCache;
        }

        $organization = Organization::query()->whereKey($this->context->organizationId())->first();
        $settings = ($organization !== null && is_array($organization->settings)) ? $organization->settings : [];
        $moderation = is_array($settings['moderation'] ?? null) ? $settings['moderation'] : [];
        $profanity = is_array($moderation['profanity'] ?? null) ? $moderation['profanity'] : [];

        $terms = is_array($profanity['terms'] ?? null) ? $profanity['terms'] : [];
        $maskChar = is_string($profanity['mask_char'] ?? null) && $profanity['mask_char'] !== ''
            ? mb_substr($profanity['mask_char'], 0, 1)
            : '*';

        return $this->profanityCache = [
            'enabled' => ($profanity['enabled'] ?? false) === true,
            'mask_char' => $maskChar,
            'terms' => array_values(array_filter(
                array_map(static fn (mixed $t): string => is_string($t) ? $t : '', $terms),
                static fn (string $t): bool => $t !== '',
            )),
        ];
    }
}
