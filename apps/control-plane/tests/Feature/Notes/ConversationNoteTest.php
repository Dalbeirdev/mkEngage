<?php

declare(strict_types=1);

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Models\Visitor;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** @return array{0: Organization, 1: string, 2: string, 3: User} org, agent token, conversation id, agent */
function noteFixture(): array
{
    $org = Organization::factory()->create();

    [$conversationId, $agent] = app(Tenancy::class)->run($org->id, function () use ($org) {
        $agent = User::factory()->create([
            'organization_id' => $org->id, 'email' => 'agent@notes.test', 'password' => Hash::make('password'),
        ]);
        $visitor = Visitor::query()->create(['organization_id' => $org->id, 'consent_state' => 'unknown']);
        $conversation = Conversation::query()->create([
            'status' => 'open', 'visitor_id' => $visitor->id,
        ]);

        return [$conversation->id, $agent];
    });

    $token = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@notes.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    return [$org, $token, $conversationId, $agent];
}

it('adds an internal note stamped with the author, and lists it', function (): void {
    [, $token, $conversationId, $agent] = noteFixture();

    $created = test()->withToken($token)
        ->postJson("/api/conversations/{$conversationId}/notes", ['body' => 'Customer is a VIP — handle with care.'])
        ->assertCreated();

    expect($created->json('body'))->toBe('Customer is a VIP — handle with care.')
        ->and($created->json('author_id'))->toBe($agent->id)
        ->and($created->json('author_name'))->toBe($agent->name);

    $list = test()->withToken($token)
        ->getJson("/api/conversations/{$conversationId}/notes")->assertOk();

    expect($list->json('data'))->toHaveCount(1)
        ->and($list->json('data.0.body'))->toBe('Customer is a VIP — handle with care.');
});

it('never turns a note into a message (stays out of the transcript)', function (): void {
    [$org, $token, $conversationId] = noteFixture();

    test()->withToken($token)
        ->postJson("/api/conversations/{$conversationId}/notes", ['body' => 'internal only'])
        ->assertCreated();

    // The message list (what a transcript / the widget would see) is untouched.
    $messages = test()->withToken($token)
        ->getJson("/api/conversations/{$conversationId}/messages")->assertOk();
    expect($messages->json('data'))->toHaveCount(0);

    app(Tenancy::class)->run($org->id, function (): void {
        expect(Message::query()->count())->toBe(0);
    });
});

it('rejects an empty note body', function (): void {
    [, $token, $conversationId] = noteFixture();

    test()->withToken($token)
        ->postJson("/api/conversations/{$conversationId}/notes", ['body' => ''])
        ->assertStatus(422);
});

it('requires authentication', function (): void {
    [, , $conversationId] = noteFixture();

    test()->getJson("/api/conversations/{$conversationId}/notes")->assertUnauthorized();
});

it('does not leak notes across tenants', function (): void {
    [, $tokenA, $conversationA] = noteFixture();
    test()->withToken($tokenA)
        ->postJson("/api/conversations/{$conversationA}/notes", ['body' => 'Org A secret note'])
        ->assertCreated();

    // A different org's agent cannot read Org A's conversation notes (works on
    // both databases — the Eloquent org scope enforces it at the app layer).
    [, $tokenB] = noteFixture();
    $this->app['auth']->forgetGuards();
    $this->withToken($tokenB)
        ->getJson("/api/conversations/{$conversationA}/notes")
        ->assertNotFound();

    // Second layer: the raw table is RLS-scoped (PostgreSQL only — SQLite has
    // no RLS). With no tenant context set, it fails closed to zero rows.
    if (runningOnPostgres()) {
        $leaked = DB::table('conversation_notes')->where('body', 'Org A secret note')->count();
        expect($leaked)->toBe(0);
    }
});
