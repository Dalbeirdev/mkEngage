<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Issues single-use password tokens — the reset flow and team invites share
 * the mechanism: rows in password_reset_tokens keyed
 * "{organization_id}|{email}" holding a sha256 token hash, consumed by
 * POST /auth/reset-password within its TTL.
 */
final class PasswordResetLinks
{
    public function issue(Organization $organization, string $email): string
    {
        $plain = bin2hex(random_bytes(32));

        DB::table('password_reset_tokens')->upsert([[
            'email' => $organization->id.'|'.$email,
            'token' => hash('sha256', $plain),
            'created_at' => now(),
        ]], ['email'], ['token', 'created_at']);

        $base = config('app.dashboard_url');
        $base = is_string($base) && $base !== '' ? rtrim($base, '/') : '';

        return $base.'/reset-password'
            .'?organization='.rawurlencode($organization->slug)
            .'&email='.rawurlencode($email)
            .'&token='.$plain;
    }
}
