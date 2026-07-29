<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor auth (RFC 6238) via pragmarx/google2fa, with a self-contained
 * SVG QR renderer (bacon/bacon-qr-code — no imagick) so the secret is rendered
 * locally and never sent to a third-party QR service.
 */
final class TwoFactorService
{
    private const RECOVERY_CODE_COUNT = 8;

    private readonly Google2FA $engine;

    public function __construct()
    {
        $this->engine = new Google2FA;
    }

    /** A fresh base32 TOTP secret (not yet confirmed by the user). */
    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    /** otpauth:// URI an authenticator app scans or imports manually. */
    public function otpauthUri(User $user, string $secret): string
    {
        return $this->engine->getQRCodeUrl(
            config()->string('app.name'),
            (string) $user->email,
            $secret,
        );
    }

    /** Inline SVG QR for the otpauth URI, rendered without external services. */
    public function qrSvg(string $otpauthUri): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(200, 1),
            new SvgImageBackEnd,
        ));

        return $writer->writeString($otpauthUri);
    }

    /** Verify a 6-digit code against the secret, allowing ±1 time step of drift. */
    public function verify(string $secret, string $code): bool
    {
        if ($secret === '' || ! ctype_digit($code)) {
            return false;
        }

        return (bool) $this->engine->verifyKey($secret, $code, window: 1);
    }

    /** The code valid right now — used only by tests, never exposed. */
    public function currentCode(string $secret): string
    {
        return $this->engine->getCurrentOtp($secret);
    }

    /**
     * Eight single-use recovery codes (format XXXXXXXXXX-XXXXXXXXXX). Stored
     * encrypted at rest; shown to the user exactly once.
     *
     * @return list<string>
     */
    public function generateRecoveryCodes(): array
    {
        return array_map(
            static fn (): string => Str::random(10).'-'.Str::random(10),
            range(1, self::RECOVERY_CODE_COUNT),
        );
    }
}
