<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * CRM management on the contacts surface: manual add (dedupe by email), CSV
 * import/export, and per-contact agent notes — all tenant-scoped.
 */

/** @return array{0: Organization, 1: string} [org, agent token] */
function crmToken(): array
{
    $organization = Organization::factory()->create();
    $email = Str::lower(Str::random(8)).'@agent.test';

    app(Tenancy::class)->run($organization->id, function () use ($email): void {
        User::factory()->create(['email' => $email]);
    });

    $token = test()->postJson('/api/auth/token', [
        'organization' => $organization->slug,
        'email' => $email,
        'password' => 'password',
        'device_name' => 'pest',
    ])->assertCreated()->json('token');

    return [$organization, $token];
}

it('manually adds a contact', function (): void {
    [, $token] = crmToken();

    $this->withToken($token)->postJson('/api/contacts', [
        'name' => 'Nadia Ito',
        'email' => 'Nadia@Example.com',
        'phone' => '+1 555 0100',
    ])->assertCreated()
        ->assertJsonPath('name', 'Nadia Ito')
        ->assertJsonPath('email', 'nadia@example.com');

    $this->withToken($token)->getJson('/api/contacts')->assertOk()->assertJsonCount(1, 'data');
});

it('rejects a duplicate email on manual add', function (): void {
    [, $token] = crmToken();

    $this->withToken($token)->postJson('/api/contacts', ['email' => 'dup@example.com'])->assertCreated();
    $this->withToken($token)->postJson('/api/contacts', ['email' => 'dup@example.com'])->assertStatus(422);
});

it('imports contacts from a CSV, skipping duplicates', function (): void {
    [, $token] = crmToken();

    $csv = implode("\n", [
        'name,email,phone',
        'Alice,alice@example.com,111',
        'Bob,bob@example.com,',
        ',carol@example.com,333',
        'Dupe,alice@example.com,999',
    ]);
    $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

    $this->withToken($token)
        ->post('/api/contacts/import', ['file' => $file], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('imported', 3)
        ->assertJsonPath('skipped', 1);

    $this->withToken($token)->getJson('/api/contacts')->assertOk()->assertJsonCount(3, 'data');
});

it('exports every contact as CSV', function (): void {
    [$organization, $token] = crmToken();
    app(Tenancy::class)->run($organization->id, function (): void {
        Contact::query()->create(['name' => 'Export Me', 'email' => 'export@example.com']);
    });

    $response = $this->withToken($token)->get('/api/contacts/export');
    $response->assertOk();

    $body = (string) $response->getContent();
    expect($body)->toContain('name,email,phone,external_id,created_at')
        ->and($body)->toContain('export@example.com');
});

it('adds and lists notes on a contact, tenant-scoped', function (): void {
    [$organization, $token] = crmToken();
    $contactId = app(Tenancy::class)->run($organization->id, fn (): string => (string) Contact::query()->create(['name' => 'Noted', 'email' => 'noted@example.com'])->id);

    $this->withToken($token)
        ->postJson("/api/contacts/{$contactId}/notes", ['body' => 'Called about renewal.'])
        ->assertCreated()
        ->assertJsonPath('body', 'Called about renewal.')
        ->assertJsonPath('author_name', fn (?string $n): bool => $n !== null);

    $this->withToken($token)->getJson("/api/contacts/{$contactId}/notes")
        ->assertOk()->assertJsonCount(1, 'data');

    // Another org cannot see or reach this contact's notes.
    [, $otherToken] = crmToken();
    $this->withToken($otherToken)->getJson("/api/contacts/{$contactId}/notes")->assertNotFound();
});
