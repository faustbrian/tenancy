<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Tenancy\Contracts\TenancyInterface;
use Cline\Tenancy\Enums\IsolationMode;
use Cline\Tenancy\Exceptions\InconsistentTenantLandlordContext;
use Cline\Tenancy\Exceptions\UnresolvedLandlordContext;
use Cline\Tenancy\Exceptions\UnresolvedTenantContext;
use Cline\Tenancy\Models\Landlord;
use Cline\Tenancy\Models\Tenant;
use Illuminate\Http\Request;

it('covers tenancy core context and payload branches', function (): void {
    $tenancy = resolve(TenancyInterface::class);

    expect($tenancy->currentTenant())->toBeNull()
        ->and($tenancy->tenant(null))->toBeNull()
        ->and($tenancy->tenantBySlug('missing'))->toBeNull();

    $tenant = Tenant::query()->create([
        'slug' => 'core',
        'name' => 'Core Tenant',
        'domains' => ['core.example.test'],
        'database' => ['connection' => 'tenant_connection'],
    ]);

    config()->set('tenancy.database.connection', 'default_connection');

    expect($tenancy->tenantBySlug('core')?->slug())->toBe('core')
        ->and($tenancy->tenantConnection())->toBe('default_connection');

    $result = $tenancy->runAsTenant($tenant, fn (): array => [
        'id' => $tenancy->tenantId(),
        'queue' => $tenancy->tenantScopedQueue('emails'),
        'payload' => $tenancy->tenancyPayload(),
        'connection' => $tenancy->tenantConnection(),
    ]);

    expect($result['id'])->toBe(1)
        ->and($result['queue'])->toBe('tenant:1:emails')
        ->and($result['connection'])->toBe('tenant_connection')
        ->and($result['payload'])->toMatchArray(['id' => 1, 'slug' => 'core'])
        ->and($tenancy->tenantScopedQueue('emails'))->toBe('emails');

    config()->set('tenancy.queue.prefix', '');
    config()->set('tenancy.queue.delimiter', '');

    $fallbackQueue = $tenancy->runAsTenant($tenant, fn (): string => $tenancy->tenantScopedQueue('jobs'));
    expect($fallbackQueue)->toBe('tenant:1:jobs');

    $asSystem = $tenancy->runAsSystem(fn () => $tenancy->currentTenant());
    expect($asSystem)->toBeNull();

    $tenancy->fromTenancyPayload(['id' => 1]);
    expect($tenancy->tenantId())->toBe(1);

    $tenancy->forgetCurrentTenant();
    $tenancy->fromTenancyPayload(['slug' => 'core']);

    expect($tenancy->tenantId())->toBe(1);

    $tenancy->forgetCurrentTenant();
    $tenancy->fromTenantPayload(['slug' => 'core']);

    expect($tenancy->tenantPayload())->toMatchArray(['id' => 1, 'slug' => 'core']);

    $tenancy->forgetCurrentTenant();
    $tenancy->fromTenancyPayload(['id' => 999]);

    expect($tenancy->currentTenant())->toBeNull();
});

it('covers landlord context lifecycle and repository iteration', function (): void {
    $tenancy = resolve(TenancyInterface::class);

    $division = Landlord::query()->create([
        'slug' => 'division-a',
        'name' => 'Division A',
    ]);

    expect($tenancy->currentLandlord())->toBeNull()
        ->and($tenancy->landlord(null))->toBeNull()
        ->and($tenancy->landlordBySlug('missing'))->toBeNull();

    config()->set('tenancy.landlord.database.connection', 'landlord_default_connection');
    config()->set('tenancy.landlord.queue.prefix', '');
    config()->set('tenancy.landlord.queue.delimiter', '');
    config()->set('tenancy.landlord.isolation', IsolationMode::SEPARATE_SCHEMA->value);

    $result = $tenancy->runAsLandlord($division, fn (): array => [
        'id' => $tenancy->landlordId(),
        'slug' => $tenancy->currentLandlord()?->slug(),
        'connection' => $tenancy->landlordConnection(),
        'isolation' => $tenancy->landlordIsolation(),
        'queue' => $tenancy->landlordScopedQueue('emails'),
        'payload' => $tenancy->landlordPayload(),
    ]);

    expect($result)->toBe([
        'id' => 1,
        'slug' => 'division-a',
        'connection' => 'landlord_default_connection',
        'isolation' => IsolationMode::SEPARATE_SCHEMA,
        'queue' => 'landlord:1:emails',
        'payload' => [
            'id' => 1,
            'slug' => 'division-a',
            'name' => 'Division A',
            'domains' => [],
        ],
    ]);

    expect($tenancy->landlordScopedQueue('emails'))->toBe('emails');

    $landlordSlugs = [];

    foreach ($tenancy->allLandlords() as $landlord) {
        $landlordSlugs[] = $landlord->slug();
    }

    expect($landlordSlugs)->toContain('division-a');

    $tenancy->fromLandlordPayload(['slug' => 'division-a']);
    expect($tenancy->landlordId())->toBe(1);

    $tenancy->forgetCurrentLandlord();
    $tenancy->fromLandlordPayload(['id' => 999]);

    expect($tenancy->currentLandlord())->toBeNull();
});

it('covers isolation mode fallback and repository all iteration', function (): void {
    $tenancy = resolve(TenancyInterface::class);

    config()->set('tenancy.isolation', IsolationMode::SEPARATE_SCHEMA->value);
    expect($tenancy->tenantIsolation())->toBe(IsolationMode::SEPARATE_SCHEMA);

    config()->set('tenancy.isolation', ['bad']);
    expect($tenancy->tenantIsolation())->toBe(IsolationMode::SHARED_DATABASE);

    Tenant::query()->create(['slug' => 'a', 'name' => 'A', 'domains' => ['a.example.test']]);
    Tenant::query()->create(['slug' => 'b', 'name' => 'B', 'domains' => ['b.example.test']]);

    $slugs = [];

    foreach ($tenancy->allTenants() as $tenant) {
        $slugs[] = $tenant->slug();
    }

    expect($slugs)->toContain('a')->toContain('b');
});

it('fails fast when run contexts cannot be resolved', function (): void {
    $tenancy = resolve(TenancyInterface::class);

    expect(fn (): mixed => $tenancy->runAsTenant('missing-tenant', static fn (): string => 'ok'))
        ->toThrow(UnresolvedTenantContext::class);

    expect(fn (): mixed => $tenancy->runAsLandlord('missing-landlord', static fn (): string => 'ok'))
        ->toThrow(UnresolvedLandlordContext::class);
});

it('can opt out of fail-fast unresolved context behavior', function (): void {
    $tenancy = resolve(TenancyInterface::class);
    config()->set('tenancy.context.require_resolved', false);

    Landlord::query()->create([
        'slug' => 'fallback-division',
        'name' => 'Fallback Division',
    ]);

    Tenant::query()->create([
        'slug' => 'fallback-tenant',
        'name' => 'Fallback Tenant',
        'landlord_id' => 1,
        'domains' => ['fallback-tenant.example.test'],
    ]);

    $stale = $tenancy->runAsTenant('fallback-tenant', fn (): array => [
        'tenant' => $tenancy->currentTenant()?->slug(),
        'landlord' => $tenancy->currentLandlord()?->slug(),
    ]);

    $tenantResult = $tenancy->runAsTenant('missing-tenant', static fn (): string => 'tenant-fallback');
    $landlordResult = $tenancy->runAsLandlord('missing-landlord', static fn (): string => 'landlord-fallback');

    $runtimeState = $tenancy->runAsTenant('missing-tenant', fn (): array => [
        'tenant' => $tenancy->currentTenant(),
        'landlord' => $tenancy->currentLandlord(),
    ]);

    expect($stale)->toBe([
        'tenant' => 'fallback-tenant',
        'landlord' => 'fallback-division',
    ])->and($tenantResult)->toBe('tenant-fallback')
        ->and($landlordResult)->toBe('landlord-fallback')
        ->and($runtimeState)->toBe([
            'tenant' => null,
            'landlord' => null,
        ]);
});

it('resolves landlord context from request via landlord resolver', function (): void {
    $tenancy = resolve(TenancyInterface::class);

    Landlord::query()->create([
        'slug' => 'division-b',
        'name' => 'Division B',
    ]);

    $request = Request::create('https://division-b.app.example.test/dashboard');

    $context = $tenancy->resolveLandlord($request);

    expect($context?->slug())->toBe('division-b')
        ->and($tenancy->landlordResolver())->not->toBeNull()
        ->and($tenancy->currentLandlord()?->slug())->toBe('division-b');
});

it('guards against inconsistent tenant and landlord context combinations', function (): void {
    $tenancy = resolve(TenancyInterface::class);

    $landlordA = Landlord::query()->create([
        'slug' => 'division-a',
        'name' => 'Division A',
    ]);

    $landlordB = Landlord::query()->create([
        'slug' => 'division-b',
        'name' => 'Division B',
    ]);

    $tenant = Tenant::query()->create([
        'slug' => 'tenant-a',
        'name' => 'Tenant A',
        'landlord_id' => $landlordA->id,
        'domains' => ['tenant-a.example.test'],
    ]);

    $tenancy->runAsTenant($tenant, function () use ($tenancy, $landlordB): void {
        expect(fn (): mixed => $tenancy->runAsLandlord($landlordB, static fn (): null => null))
            ->toThrow(InconsistentTenantLandlordContext::class);
    });
});

it('clears stale landlord context when tenant landlord cannot be resolved', function (): void {
    $tenancy = resolve(TenancyInterface::class);

    $landlord = Landlord::query()->create([
        'slug' => 'division-stale',
        'name' => 'Division Stale',
    ]);

    $tenant = Tenant::query()->create([
        'slug' => 'tenant-stale',
        'name' => 'Tenant Stale',
        'domains' => ['tenant-stale.example.test'],
        'landlord_id' => 999,
    ]);

    $tenancy->runAsLandlord($landlord, function () use ($tenancy, $tenant): void {
        expect($tenancy->currentLandlord()?->slug())->toBe('division-stale');

        $tenancy->runAsTenant($tenant, function () use ($tenancy): void {
            expect($tenancy->currentLandlord())->toBeNull();
        });

        expect($tenancy->currentLandlord()?->slug())->toBe('division-stale');
    });
});

it('clears stale landlord context when hydrating tenant payload', function (): void {
    $tenancy = resolve(TenancyInterface::class);

    Landlord::query()->create([
        'slug' => 'division-payload',
        'name' => 'Division Payload',
    ]);

    Tenant::query()->create([
        'slug' => 'tenant-payload',
        'name' => 'Tenant Payload',
        'domains' => ['tenant-payload.example.test'],
        'landlord_id' => 999,
    ]);

    $tenancy->fromLandlordPayload(['slug' => 'division-payload']);
    expect($tenancy->currentLandlord()?->slug())->toBe('division-payload');

    $tenancy->fromTenantPayload(['slug' => 'tenant-payload']);

    expect($tenancy->currentTenant()?->slug())->toBe('tenant-payload')
        ->and($tenancy->currentLandlord())->toBeNull();
});

it('does not hydrate landlord from mixed payload when tenant cannot resolve', function (): void {
    $tenancy = resolve(TenancyInterface::class);

    Landlord::query()->create([
        'slug' => 'division-mixed-payload',
        'name' => 'Division Mixed Payload',
    ]);

    $tenancy->fromTenancyPayload([
        'tenant' => ['slug' => 'missing-tenant'],
        'landlord' => ['slug' => 'division-mixed-payload'],
    ]);

    expect($tenancy->currentTenant())->toBeNull()
        ->and($tenancy->currentLandlord())->toBeNull();
});

it('does not treat tenant payload metadata as nested tenancy envelope', function (): void {
    $tenancy = resolve(TenancyInterface::class);

    Tenant::query()->create([
        'slug' => 'envelope-safe',
        'name' => 'Envelope Safe',
        'domains' => ['envelope-safe.example.test'],
    ]);

    $tenancy->fromTenancyPayload([
        'id' => 1,
        'slug' => 'envelope-safe',
        'landlord' => ['custom' => true],
    ]);

    expect($tenancy->currentTenant()?->slug())->toBe('envelope-safe')
        ->and($tenancy->currentLandlord())->toBeNull();
});

it('runs system context without tenant or landlord even when sync is disabled', function (): void {
    $tenancy = resolve(TenancyInterface::class);
    config()->set('tenancy.landlord.sync_with_tenant', false);

    $landlord = Landlord::query()->create([
        'slug' => 'division-system',
        'name' => 'Division System',
    ]);

    $state = $tenancy->runAsLandlord($landlord, function () use ($tenancy): array {
        $inside = $tenancy->runAsSystem(fn (): array => [
            'tenant' => $tenancy->currentTenant(),
            'landlord' => $tenancy->currentLandlord(),
        ]);

        return [
            'inside' => $inside,
            'after' => $tenancy->currentLandlord()?->slug(),
        ];
    });

    expect($state)->toBe([
        'inside' => [
            'tenant' => null,
            'landlord' => null,
        ],
        'after' => 'division-system',
    ]);
});
