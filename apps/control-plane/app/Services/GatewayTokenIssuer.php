<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Mints short-lived gateway socket tokens (ADR-002): compact
 * base64url(payload).base64url(HMAC-SHA256(payload, GATEWAY_SIGNING_KEY)).
 * Claims: org, sub ("visitor:<id>" | "user:<id>"), exp (≤ 5 minutes).
 *
 * Interim shared-secret scheme; the ADR's target is Ed25519 + JWKS with the
 * same claim contract. Tokens carry IDENTITY only — the gateway re-checks
 * conversation access in the database on every join.
 */
final class GatewayTokenIssuer
{
    private const TTL_SECONDS = 300;

    public function issueForVisitor(string $organizationId, string $visitorId): string
    {
        return $this->issue($organizationId, 'visitor:'.$visitorId);
    }

    public function issueForUser(string $organizationId, string $userId): string
    {
        return $this->issue($organizationId, 'user:'.$userId);
    }

    private function issue(string $organizationId, string $sub): string
    {
        $key = config('services.gateway.signing_key');

        if (! is_string($key) || $key === '') {
            throw new \RuntimeException('GATEWAY_SIGNING_KEY is not configured.');
        }

        $payload = json_encode([
            'org' => $organizationId,
            'sub' => $sub,
            'exp' => time() + self::TTL_SECONDS,
        ], JSON_THROW_ON_ERROR);

        $signature = hash_hmac('sha256', $payload, $key, true);

        return $this->base64url($payload).'.'.$this->base64url($signature);
    }

    private function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
