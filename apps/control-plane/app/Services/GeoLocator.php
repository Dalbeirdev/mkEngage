<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;

/**
 * Resolves a coarse location from the edge proxy's geo headers — Cloudflare
 * (country only), Vercel, Google App Engine, and generic reverse-proxy
 * conventions. No third-party lookup and no IP is stored: privacy-preserving
 * by construction. Returns nulls when running without a geo-aware edge (e.g.
 * local dev), which is expected.
 */
final class GeoLocator
{
    /** @return array{country_code: string|null, city: string|null} */
    public function locate(Request $request): array
    {
        // Try each country header in order, skipping Cloudflare sentinels
        // (XX = unknown, T1 = Tor) so a later header can still resolve.
        $countryCode = null;
        foreach (['CF-IPCountry', 'X-Vercel-IP-Country', 'X-AppEngine-Country', 'X-Geo-Country'] as $name) {
            $raw = $request->header($name);
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }
            $code = strtoupper(substr(trim($raw), 0, 2));
            if (in_array($code, ['XX', 'T1'], true)) {
                continue;
            }
            $countryCode = $code;
            break;
        }

        $city = $this->header($request, ['X-Vercel-IP-City', 'X-AppEngine-City', 'X-Geo-City']);

        if ($city !== null) {
            // Vercel percent-encodes the city (e.g. "San%20Francisco").
            $city = trim(rawurldecode($city));
            if ($city === '') {
                $city = null;
            }
        }

        return ['country_code' => $countryCode, 'city' => $city];
    }

    /**
     * First non-empty value among the candidate headers.
     *
     * @param  list<string>  $names
     */
    private function header(Request $request, array $names): ?string
    {
        foreach ($names as $name) {
            $value = $request->header($name);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
