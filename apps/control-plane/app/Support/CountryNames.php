<?php

declare(strict_types=1);

namespace App\Support;

/**
 * ISO-3166 alpha-2 → English country name. Covers the common set; unknown
 * codes fall back to the code itself so display never breaks.
 */
final class CountryNames
{
    /** @var array<string, string> */
    private const NAMES = [
        'AE' => 'United Arab Emirates', 'AR' => 'Argentina', 'AU' => 'Australia', 'AT' => 'Austria',
        'BD' => 'Bangladesh', 'BE' => 'Belgium', 'BR' => 'Brazil', 'CA' => 'Canada', 'CH' => 'Switzerland',
        'CL' => 'Chile', 'CN' => 'China', 'CO' => 'Colombia', 'CZ' => 'Czechia', 'DE' => 'Germany',
        'DK' => 'Denmark', 'EG' => 'Egypt', 'ES' => 'Spain', 'FI' => 'Finland', 'FR' => 'France',
        'GB' => 'United Kingdom', 'GR' => 'Greece', 'HK' => 'Hong Kong', 'HU' => 'Hungary',
        'ID' => 'Indonesia', 'IE' => 'Ireland', 'IL' => 'Israel', 'IN' => 'India', 'IT' => 'Italy',
        'JP' => 'Japan', 'KE' => 'Kenya', 'KR' => 'South Korea', 'LK' => 'Sri Lanka', 'MX' => 'Mexico',
        'MY' => 'Malaysia', 'NG' => 'Nigeria', 'NL' => 'Netherlands', 'NO' => 'Norway', 'NP' => 'Nepal',
        'NZ' => 'New Zealand', 'PH' => 'Philippines', 'PK' => 'Pakistan', 'PL' => 'Poland',
        'PT' => 'Portugal', 'RO' => 'Romania', 'RU' => 'Russia', 'SA' => 'Saudi Arabia', 'SE' => 'Sweden',
        'SG' => 'Singapore', 'TH' => 'Thailand', 'TR' => 'Türkiye', 'TW' => 'Taiwan', 'UA' => 'Ukraine',
        'US' => 'United States', 'VN' => 'Vietnam', 'ZA' => 'South Africa',
    ];

    public static function name(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        $upper = strtoupper($code);

        return self::NAMES[$upper] ?? $upper;
    }
}
