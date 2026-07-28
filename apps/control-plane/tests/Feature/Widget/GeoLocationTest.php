<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Visitor;
use App\Tenancy\Tenancy;

/**
 * Coarse visitor geolocation from edge proxy headers — captured at session
 * bootstrap, surfaced as "City, Country". No IP stored, null without an edge.
 */

it('captures visitor location from edge geo headers at session bootstrap', function (): void {
    $org = Organization::factory()->create();

    $visitorId = test()
        ->withHeaders(['CF-IPCountry' => 'IN', 'X-Vercel-IP-City' => 'Bangalore'])
        ->postJson('/api/widget/session', ['site_key' => $org->widget_site_key])
        ->assertCreated()
        ->json('visitor_id');

    app(Tenancy::class)->run($org->id, function () use ($visitorId): void {
        $visitor = Visitor::query()->findOrFail($visitorId);
        expect($visitor->country_code)->toBe('IN')
            ->and($visitor->city)->toBe('Bangalore')
            ->and($visitor->location())->toBe('Bangalore, India');
    });
});

it('decodes a percent-encoded city and ignores Cloudflare sentinels', function (): void {
    $org = Organization::factory()->create();

    $visitorId = test()
        ->withHeaders(['CF-IPCountry' => 'XX', 'X-Vercel-IP-City' => 'San%20Francisco', 'X-Vercel-IP-Country' => 'US'])
        ->postJson('/api/widget/session', ['site_key' => $org->widget_site_key])
        ->assertCreated()
        ->json('visitor_id');

    app(Tenancy::class)->run($org->id, function () use ($visitorId): void {
        $visitor = Visitor::query()->findOrFail($visitorId);
        // CF-IPCountry XX is a sentinel; the Vercel country header wins.
        expect($visitor->country_code)->toBe('US')
            ->and($visitor->city)->toBe('San Francisco')
            ->and($visitor->location())->toBe('San Francisco, United States');
    });
});

it('leaves location null without geo headers (local dev)', function (): void {
    $org = Organization::factory()->create();

    $visitorId = test()->postJson('/api/widget/session', ['site_key' => $org->widget_site_key])
        ->assertCreated()->json('visitor_id');

    app(Tenancy::class)->run($org->id, function () use ($visitorId): void {
        $visitor = Visitor::query()->findOrFail($visitorId);
        expect($visitor->country_code)->toBeNull()
            ->and($visitor->location())->toBeNull();
    });
});
