<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\MissingTenantContextException;
use App\Tenancy\Tenancy;
use App\Tenancy\TenantContext;

/**
 * Application-layer isolation (ADR-007 layer 1). Driver-agnostic — these
 * behaviors must hold even when the database has no RLS (which is exactly why
 * layer 1 exists). The PostgreSQL layer is proven in RlsIsolationTest.
 */
it('fails closed: querying a tenant model without context throws', function (): void {
    Organization::factory()->create();

    expect(fn () => User::query()->count())
        ->toThrow(MissingTenantContextException::class);
});

it('fails closed: creating a tenant model without context throws', function (): void {
    $organization = Organization::factory()->create();

    expect(fn () => User::factory()->create(['organization_id' => $organization->id]))
        ->toThrow(MissingTenantContextException::class);
});

it('scopes all queries to the established organization', function (): void {
    $tenancy = app(Tenancy::class);

    [$orgA, $orgB] = Organization::factory()->count(2)->create();

    $tenancy->run($orgA->id, function (): void {
        User::factory()->count(2)->create();
        Role::query()->create(['name' => 'Admin']);
        Department::query()->create(['name' => 'Support']);
    });

    $tenancy->run($orgB->id, function (): void {
        User::factory()->create();
    });

    // Find org B's user id outside any tenant context (test-only bypass).
    $orgBUserId = User::query()->withoutGlobalScope('organization')
        ->where('organization_id', $orgB->id)->value('id');

    $tenancy->run($orgA->id, function () use ($orgBUserId): void {
        expect(User::query()->count())->toBe(2)
            ->and(Role::query()->count())->toBe(1)
            ->and(Department::query()->count())->toBe(1)
            // Direct lookup of org B's row returns nothing — not an error,
            // not a leak: it does not exist inside this context.
            ->and(User::query()->whereKey($orgBUserId)->exists())->toBeFalse();
    });

    $tenancy->run($orgB->id, function (): void {
        expect(User::query()->count())->toBe(1)
            ->and(Role::query()->count())->toBe(0);
    });
});

it('fills organization_id from context on create', function (): void {
    $tenancy = app(Tenancy::class);
    $organization = Organization::factory()->create();

    $user = $tenancy->run($organization->id, fn (): User => User::factory()->create());

    expect($user->organization_id)->toBe($organization->id);
});

it('rejects creates whose organization_id contradicts the context', function (): void {
    $tenancy = app(Tenancy::class);
    [$orgA, $orgB] = Organization::factory()->count(2)->create();

    expect(fn () => $tenancy->run($orgA->id, fn (): User => User::factory()->create([
        'organization_id' => $orgB->id,
    ])))->toThrow(MissingTenantContextException::class);
});

it('clears context after each run and allows sequential runs', function (): void {
    $tenancy = app(Tenancy::class);
    $context = app(TenantContext::class);
    [$orgA, $orgB] = Organization::factory()->count(2)->create();

    $tenancy->run($orgA->id, function () use ($context, $orgA): void {
        expect($context->organizationId())->toBe($orgA->id);
    });

    expect($context->established())->toBeFalse();

    $tenancy->run($orgB->id, function () use ($context, $orgB): void {
        expect($context->organizationId())->toBe($orgB->id);
    });

    expect($context->established())->toBeFalse();
});

it('forbids nested cross-organization runs inside a transaction', function (): void {
    $tenancy = app(Tenancy::class);
    [$orgA, $orgB] = Organization::factory()->count(2)->create();

    expect(fn () => $tenancy->run($orgA->id, fn (): mixed => $tenancy->run($orgB->id, fn (): int => 1)))
        ->toThrow(MissingTenantContextException::class);
});

it('allows nested same-organization runs', function (): void {
    $tenancy = app(Tenancy::class);
    $organization = Organization::factory()->create();

    $result = $tenancy->run($organization->id, fn (): int => $tenancy->run($organization->id, fn (): int => 42));

    expect($result)->toBe(42);
});
