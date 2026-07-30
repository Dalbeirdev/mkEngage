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

    /**
     * True when this IP is on the current org's ban list (RLS-scoped) —
     * either as an exact address or inside a banned CIDR range.
     */
    public function isIpBanned(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        if (ModerationIpBan::query()->where('ip_address', $ip)->exists()) {
            return true;
        }

        // CIDR entries can't be matched in SQL portably — the list is small
        // (bounded by admin input), so scan the range bans in PHP.
        return ModerationIpBan::query()
            ->where('ip_address', 'like', '%/%')
            ->pluck('ip_address')
            ->contains(fn (string $cidr): bool => self::ipInCidr($ip, $cidr));
    }

    /** Exact-bit CIDR containment for IPv4 and IPv6 (inet_pton byte math). */
    public static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        if (! is_string($subnet) || ! is_string($bits) || ! ctype_digit($bits)) {
            return false;
        }

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false; // mixed families or malformed input
        }

        $prefix = (int) $bits;
        $maxBits = strlen($ipBin) * 8;
        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainder = $prefix % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainder) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
    }

    /**
     * Replace each configured profanity term (whole word, case-insensitive)
     * with a run of the mask character. A no-op when disabled or no terms —
     * the original body passes through untouched.
     */
    public function maskProfanity(string $body): string
    {
        return $this->maskProfanityDetail($body)['body'];
    }

    /**
     * Like maskProfanity, but also reports whether anything was masked.
     *
     * @return array{body: string, masked: bool}
     */
    public function maskProfanityDetail(string $body): array
    {
        $config = $this->profanityConfig();
        if ($config['enabled'] !== true || $config['terms'] === []) {
            return ['body' => $body, 'masked' => false];
        }

        $masked = false;
        $mask = $config['mask_char'];
        foreach ($config['terms'] as $term) {
            if ($term === '') {
                continue;
            }
            $pattern = '/\b'.preg_quote($term, '/').'\b/iu';
            $body = preg_replace_callback(
                $pattern,
                static function (array $match) use ($mask, &$masked): string {
                    $masked = true;

                    return str_repeat($mask, max(1, mb_strlen($match[0])));
                },
                $body,
            ) ?? $body;
        }

        return ['body' => $body, 'masked' => $masked];
    }

    /** @return array{enabled: bool, threshold: int} auto-close-on-abuse config */
    public function autoCloseConfig(): array
    {
        $moderation = $this->moderationSettings();
        $autoClose = is_array($moderation['auto_close'] ?? null) ? $moderation['auto_close'] : [];
        $threshold = $autoClose['threshold'] ?? null;

        return [
            'enabled' => ($autoClose['enabled'] ?? false) === true,
            'threshold' => is_int($threshold) && $threshold >= 1 ? $threshold : 3,
        ];
    }

    /** @return array<string, mixed> the org's moderation settings block */
    private function moderationSettings(): array
    {
        $organization = Organization::query()->whereKey($this->context->organizationId())->first();
        $settings = ($organization !== null && is_array($organization->settings)) ? $organization->settings : [];

        return is_array($settings['moderation'] ?? null) ? $settings['moderation'] : [];
    }

    /** @return array{enabled: bool, mask_char: string, terms: list<string>} */
    private function profanityConfig(): array
    {
        if ($this->profanityCache !== null) {
            return $this->profanityCache;
        }

        $moderation = $this->moderationSettings();
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
