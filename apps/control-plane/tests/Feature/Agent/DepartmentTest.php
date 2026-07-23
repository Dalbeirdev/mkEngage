<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Support\Str;

function deptToken(): array
{
    $organization = Organization::factory()->create();
    $email = Str::lower(Str::random(8)).'@admin.test';

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

it('makes the first department the default automatically', function (): void {
    [, $token] = deptToken();

    $first = $this->withToken($token)->postJson('/api/departments', ['name' => 'Support'])
        ->assertCreated();
    $second = $this->withToken($token)->postJson('/api/departments', ['name' => 'Sales'])
        ->assertCreated();

    expect($first->json('is_default'))->toBeTrue()
        ->and($second->json('is_default'))->toBeFalse();
});

it('enforces the single-default invariant on update', function (): void {
    [, $token] = deptToken();

    $a = $this->withToken($token)->postJson('/api/departments', ['name' => 'A'])->json('department_id');
    $b = $this->withToken($token)->postJson('/api/departments', ['name' => 'B'])->json('department_id');

    $this->withToken($token)->patchJson("/api/departments/{$b}", ['is_default' => true])->assertOk();

    $list = collect($this->withToken($token)->getJson('/api/departments')->json('data'))
        ->keyBy('department_id');

    expect($list[$a]['is_default'])->toBeFalse()
        ->and($list[$b]['is_default'])->toBeTrue();
});

it('routes new widget conversations to the default department', function (): void {
    [$organization, $token] = deptToken();

    $departmentId = $this->withToken($token)
        ->postJson('/api/departments', ['name' => 'Front Desk'])
        ->json('department_id');

    $visitorToken = $this->postJson('/api/widget/session', [
        'site_key' => $organization->widget_site_key,
    ])->assertCreated()->json('token');

    $conversationId = $this->withToken($visitorToken)
        ->postJson('/api/widget/conversations', [])
        ->assertCreated()->json('conversation_id');

    $shown = $this->withToken($token)->getJson("/api/conversations/{$conversationId}")->assertOk();

    expect($shown->json('department_id'))->toBe($departmentId)
        ->and($shown->json('department_name'))->toBe('Front Desk');
});

it('manages members with PUT semantics and counts them', function (): void {
    [$organization, $token] = deptToken();

    $departmentId = $this->withToken($token)
        ->postJson('/api/departments', ['name' => 'Team'])->json('department_id');

    $userIds = app(Tenancy::class)->run($organization->id, function (): array {
        return User::factory()->count(3)->create()->pluck('id')->all();
    });

    $set = $this->withToken($token)->putJson("/api/departments/{$departmentId}/members", [
        'user_ids' => $userIds,
    ])->assertOk();
    expect($set->json('member_count'))->toBe(3);

    // Replace with a smaller set — PUT semantics.
    $replace = $this->withToken($token)->putJson("/api/departments/{$departmentId}/members", [
        'user_ids' => [$userIds[0]],
    ])->assertOk();
    expect($replace->json('member_count'))->toBe(1);
});

it('ignores cross-org user ids in member sets', function (): void {
    [, $token] = deptToken();
    $departmentId = $this->withToken($token)
        ->postJson('/api/departments', ['name' => 'Team'])->json('department_id');

    $other = Organization::factory()->create();
    $foreignUserId = app(Tenancy::class)->run($other->id, function (): string {
        return User::factory()->create()->id;
    });

    $set = $this->withToken($token)->putJson("/api/departments/{$departmentId}/members", [
        'user_ids' => [$foreignUserId],
    ])->assertOk();

    expect($set->json('member_count'))->toBe(0);
});

it('filters conversations by department', function (): void {
    [$organization, $token] = deptToken();

    $deptA = $this->withToken($token)->postJson('/api/departments', ['name' => 'A'])->json('department_id');
    $deptB = $this->withToken($token)->postJson('/api/departments', ['name' => 'B'])->json('department_id');

    // Conversation lands in default (A); transfer it to B, then create another in A.
    $visitorToken = $this->postJson('/api/widget/session', [
        'site_key' => $organization->widget_site_key,
    ])->json('token');

    $conv1 = $this->withToken($visitorToken)->postJson('/api/widget/conversations', [])->json('conversation_id');
    $this->withToken($token)->patchJson("/api/conversations/{$conv1}", ['department_id' => $deptB])->assertOk();

    $visitor2 = $this->postJson('/api/widget/session', [
        'site_key' => $organization->widget_site_key,
    ])->json('token');
    $conv2 = $this->withToken($visitor2)->postJson('/api/widget/conversations', [])->json('conversation_id');

    $inA = $this->withToken($token)->getJson("/api/conversations?department_id={$deptA}")->json('data');
    $inB = $this->withToken($token)->getJson("/api/conversations?department_id={$deptB}")->json('data');

    expect(collect($inA)->pluck('conversation_id'))->toContain($conv2)->not->toContain($conv1)
        ->and(collect($inB)->pluck('conversation_id'))->toContain($conv1)->not->toContain($conv2);
});

it('is tenant-scoped and blocks visitor tokens', function (): void {
    [$organization, $token] = deptToken();
    $departmentId = $this->withToken($token)
        ->postJson('/api/departments', ['name' => 'Private'])->json('department_id');

    [, $otherToken] = deptToken();
    $this->app['auth']->forgetGuards(); // Sanctum guard caches the resolved user per test
    expect($this->withToken($otherToken)->getJson('/api/departments')->json('data'))->toHaveCount(0);
    $this->withToken($otherToken)->patchJson("/api/departments/{$departmentId}", ['name' => 'x'])
        ->assertNotFound();

    $visitorToken = $this->postJson('/api/widget/session', [
        'site_key' => $organization->widget_site_key,
    ])->json('token');
    $this->app['auth']->forgetGuards();
    $this->withToken($visitorToken)->getJson('/api/departments')->assertStatus(403);
    $this->withToken($visitorToken)->getJson('/api/users')->assertStatus(403);
});
