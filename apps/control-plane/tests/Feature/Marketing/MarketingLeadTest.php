<?php

declare(strict_types=1);

use App\Models\ContactSubmission;
use App\Models\NewsletterSubscription;

/**
 * Public marketing leads: the contact form and newsletter opt-in persist
 * without any tenant context (platform-global) and validate their input.
 */
it('stores a contact submission', function (): void {
    $this->postJson('/api/contact', [
        'name' => 'Dana Lee',
        'email' => 'Dana@Example.com',
        'company' => 'Acme Inc',
        'subject' => 'Sales',
        'message' => 'We would like a demo for 30 agents.',
    ])->assertCreated()->assertJsonPath('status', 'received');

    $row = ContactSubmission::query()->firstOrFail();
    expect($row->email)->toBe('dana@example.com') // normalized
        ->and($row->name)->toBe('Dana Lee')
        ->and($row->company)->toBe('Acme Inc');
});

it('accepts a contact submission without the optional fields', function (): void {
    $this->postJson('/api/contact', [
        'name' => 'No Company',
        'email' => 'nc@example.com',
        'message' => 'Just a question.',
    ])->assertCreated();

    expect(ContactSubmission::query()->count())->toBe(1);
});

it('rejects an invalid contact submission', function (): void {
    $this->postJson('/api/contact', ['name' => '', 'email' => 'not-an-email', 'message' => ''])
        ->assertStatus(422);

    expect(ContactSubmission::query()->count())->toBe(0);
});

it('subscribes an email to the newsletter', function (): void {
    $this->postJson('/api/newsletter', ['email' => 'Reader@Example.com'])
        ->assertCreated()->assertJsonPath('status', 'subscribed');

    $row = NewsletterSubscription::query()->firstOrFail();
    expect($row->email)->toBe('reader@example.com')
        ->and($row->source)->toBe('website');
});

it('is idempotent on repeat newsletter subscriptions', function (): void {
    $this->postJson('/api/newsletter', ['email' => 'dup@example.com'])->assertCreated();
    $this->postJson('/api/newsletter', ['email' => 'dup@example.com'])->assertCreated();

    expect(NewsletterSubscription::query()->where('email', 'dup@example.com')->count())->toBe(1);
});

it('rejects an invalid newsletter email', function (): void {
    $this->postJson('/api/newsletter', ['email' => 'nope'])->assertStatus(422);

    expect(NewsletterSubscription::query()->count())->toBe(0);
});
