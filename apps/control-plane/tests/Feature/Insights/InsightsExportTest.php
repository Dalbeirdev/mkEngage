<?php

declare(strict_types=1);

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Insights CSV export: authenticated download with every report section.
 */
it('downloads the insights overview as CSV', function (): void {
    $org = Organization::factory()->create();

    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id, 'email' => 'agent@insexp.test',
            'name' => 'Export Agent', 'password' => Hash::make('password'),
        ]);
        $conversation = Conversation::query()->create([]);
        Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'visitor',
            'sender_id' => Str::uuid7()->toString(),
            'sequence_number' => 1,
            'content_type' => 'text',
            'body' => 'export me',
            'lifecycle_state' => 'persisted',
            'idempotency_key' => Str::uuid7()->toString(),
            'sent_at' => now()->toDateTimeString(),
        ]);
        $conversation->last_sequence = 1;
        $conversation->save();
    });
    auth()->forgetGuards();

    $token = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@insexp.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    $response = test()->withToken($token)->get('/api/insights/export');

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    expect((string) $response->headers->get('Content-Disposition'))->toContain('attachment')
        ->toContain('mkengage-insights-');

    $csv = $response->getContent();
    expect($csv)->toBeString()
        ->toContain('mkEngage Insights')
        ->toContain('Metric,Value')
        ->toContain('Conversations,1')
        ->toContain('Daily')
        ->toContain('Channels')
        ->toContain('web,1')
        ->toContain('Hourly')
        ->toContain('SLA tracked');
});

it('rejects an inverted date range', function (): void {
    $org = Organization::factory()->create();
    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id, 'email' => 'agent@insexp2.test',
            'name' => 'Export Agent', 'password' => Hash::make('password'),
        ]);
    });
    auth()->forgetGuards();

    $token = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@insexp2.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    test()->withToken($token)
        ->getJson('/api/insights/export?from=2026-07-30&to=2026-07-01')
        ->assertStatus(422);
});
