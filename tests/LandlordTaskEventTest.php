<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Tenancy\Contracts\TenancyInterface;
use Cline\Tenancy\Events\LandlordEnded;
use Cline\Tenancy\Events\LandlordResolved;
use Cline\Tenancy\Events\LandlordResolving;
use Cline\Tenancy\Events\LandlordSwitched;
use Cline\Tenancy\Events\TenancyEnded;
use Cline\Tenancy\Events\TenantResolved;
use Cline\Tenancy\Events\TenantResolving;
use Cline\Tenancy\Events\TenantSwitched;
use Cline\Tenancy\Models\Landlord;
use Cline\Tenancy\Models\Tenant;
use Cline\Tenancy\Tasks\MapLandlordConfigTask;
use Cline\Tenancy\Tasks\MapTenantConfigTask;
use Cline\Tenancy\Tasks\PrefixCacheTask;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\FakeLandlordTask;
use Tests\Fixtures\FakeTask;

it('runs configured tasks while switching tenant context', function (): void {
    FakeTask::reset();

    config()->set('tenancy.tasks', [FakeTask::class]);
    app()->forgetInstance(TenancyInterface::class);

    $tenantA = Tenant::query()->create([
        'slug' => 'task-a',
        'name' => 'TaskInterface A',
        'domains' => ['task-a.example.test'],
    ]);

    $tenantB = Tenant::query()->create([
        'slug' => 'task-b',
        'name' => 'TaskInterface B',
        'domains' => ['task-b.example.test'],
    ]);

    $tenancy = resolve(TenancyInterface::class);

    $tenancy->runAsTenant($tenantA, function () use ($tenancy, $tenantB): void {
        $tenancy->runAsTenant($tenantB, static function (): void {});
    });

    expect(FakeTask::$made)->toBe([1, 2, 1])
        ->and(FakeTask::$forgotten)->toBe([1, 2, 1]);
});

it('runs configured landlord tasks while switching landlord context', function (): void {
    FakeLandlordTask::reset();

    config()->set('tenancy.landlord.tasks', [FakeLandlordTask::class]);
    app()->forgetInstance(TenancyInterface::class);

    $landlordA = Landlord::query()->create([
        'slug' => 'division-a',
        'name' => 'Division A',
    ]);

    $landlordB = Landlord::query()->create([
        'slug' => 'division-b',
        'name' => 'Division B',
    ]);

    $tenancy = resolve(TenancyInterface::class);

    $tenancy->runAsLandlord($landlordA, function () use ($tenancy, $landlordB): void {
        $tenancy->runAsLandlord($landlordB, static function (): void {});
    });

    expect(FakeLandlordTask::$made)->toBe([1, 2, 1])
        ->and(FakeLandlordTask::$forgotten)->toBe([1, 2, 1]);
});

it('prefixes and restores cache config via built-in task', function (): void {
    $tenant = Tenant::query()->create([
        'slug' => 'cache',
        'name' => 'Cache Tenant',
        'domains' => ['cache.example.test'],
    ]);

    config()->set('cache.prefix', 'app');
    config()->set('tenancy.cache.prefix', 'tenant');
    config()->set('tenancy.cache.delimiter', ':');

    $task = resolve(PrefixCacheTask::class);
    $context = resolve(TenancyInterface::class)->tenant($tenant);

    expect($context)->not->toBeNull();

    $task->makeCurrent($context);

    expect(config('cache.prefix'))->toBe('tenant:1');

    $task->forgetCurrent($context);

    expect(config('cache.prefix'))->toBe('app');
});

it('maps and restores tenant config via built-in task', function (): void {
    $tenant = Tenant::query()->create([
        'slug' => 'tenant-config',
        'name' => 'Tenant Config Name',
        'domains' => ['tenant-config.example.test'],
    ]);

    config()->set('app.name', 'Host App');
    config()->set('mail.from.address', 'host@example.test');
    config()->set('tenancy.config_mapping.mappings', [
        'app.name' => 'name',
        'mail.from.address' => 'slug',
    ]);

    $task = resolve(MapTenantConfigTask::class);
    $context = resolve(TenancyInterface::class)->tenant($tenant);

    expect($context)->not->toBeNull();

    $task->makeCurrent($context);

    expect(config('app.name'))->toBe('Tenant Config Name')
        ->and(config('mail.from.address'))->toBe('tenant-config');

    $task->forgetCurrent($context);

    expect(config('app.name'))->toBe('Host App')
        ->and(config('mail.from.address'))->toBe('host@example.test');
});

it('maps and restores landlord config via built-in task', function (): void {
    $landlord = Landlord::query()->create([
        'slug' => 'division-config',
        'name' => 'Division Config Name',
    ]);

    config()->set('app.name', 'Host App');
    config()->set('queue.default', 'sync');
    config()->set('tenancy.landlord.config_mapping.mappings', [
        'app.name' => 'name',
        'queue.default' => 'slug',
    ]);

    $task = resolve(MapLandlordConfigTask::class);
    $context = resolve(TenancyInterface::class)->landlord($landlord);

    expect($context)->not->toBeNull();

    $task->makeCurrent($context);

    expect(config('app.name'))->toBe('Division Config Name')
        ->and(config('queue.default'))->toBe('division-config');

    $task->forgetCurrent($context);

    expect(config('app.name'))->toBe('Host App')
        ->and(config('queue.default'))->toBe('sync');
});

it('dispatches tenant and landlord lifecycle events', function (): void {
    Event::fake([
        LandlordResolving::class,
        LandlordResolved::class,
        LandlordEnded::class,
        TenantResolving::class,
        TenantResolved::class,
        TenantSwitched::class,
        TenancyEnded::class,
        LandlordSwitched::class,
    ]);

    $landlord = Landlord::query()->create([
        'slug' => 'division-a',
        'name' => 'Division A',
    ]);

    Tenant::query()->create([
        'slug' => 'events',
        'name' => 'Events Tenant',
        'landlord_id' => $landlord->id,
        'domains' => ['events.example.test'],
    ]);

    $tenancy = resolve(TenancyInterface::class);

    $landlordRequest = Request::create('https://division-a.app.example.test/dashboard');
    $tenancy->resolveLandlord($landlordRequest);
    $tenancy->resolveTenant(Request::create('https://events.example.test/dashboard'));

    expect($tenancy->currentTenant()?->slug())->toBe('events');
    expect($tenancy->currentLandlord()?->slug())->toBe('division-a');

    $tenancy->runAsLandlord($landlord, function () use ($tenancy): void {
        expect($tenancy->currentLandlord()?->slug())->toBe('division-a');
    });

    expect($tenancy->currentLandlord()?->slug())->toBe('division-a');

    $tenancy->forgetCurrentTenant();

    Event::assertDispatched(TenantResolving::class);
    Event::assertDispatched(TenantResolved::class);
    Event::assertDispatched(LandlordResolving::class);
    Event::assertDispatched(LandlordResolved::class);
    Event::assertDispatched(TenantSwitched::class);
    Event::assertDispatched(TenancyEnded::class);
    Event::assertDispatched(LandlordEnded::class);
    Event::assertDispatched(LandlordSwitched::class);
});

it('dispatches tenant resolved after landlord context sync', function (): void {
    Event::fake([TenantResolved::class]);

    $landlord = Landlord::query()->create([
        'slug' => 'division-order',
        'name' => 'Division Order',
    ]);

    Tenant::query()->create([
        'slug' => 'events-order',
        'name' => 'Events Order',
        'landlord_id' => $landlord->id,
        'domains' => ['events-order.example.test'],
    ]);

    $tenancy = resolve(TenancyInterface::class);
    $tenancy->resolveTenant(Request::create('https://events-order.example.test/dashboard'));

    Event::assertDispatched(TenantResolved::class, fn (): bool => $tenancy->currentLandlord()?->slug() === 'division-order');
});

it('hydrates tenant and landlord context from queue payload', function (): void {
    $landlord = Landlord::query()->create([
        'slug' => 'payload-division',
        'name' => 'Payload Division',
    ]);

    Tenant::query()->create([
        'slug' => 'payload-tenant',
        'name' => 'Payload Tenant',
        'landlord_id' => $landlord->id,
        'domains' => ['payload.example.test'],
    ]);

    $tenancy = resolve(TenancyInterface::class);

    $tenancy->fromTenancyPayload([
        'tenant' => ['slug' => 'payload-tenant'],
    ]);

    expect($tenancy->currentTenant()?->slug())->toBe('payload-tenant')
        ->and($tenancy->currentLandlord()?->slug())->toBe('payload-division')
        ->and($tenancy->tenancyPayload())->toBe([
            'tenant' => [
                'id' => 1,
                'slug' => 'payload-tenant',
                'name' => 'Payload Tenant',
                'domains' => ['payload.example.test'],
                'landlord_id' => 1,
            ],
            'landlord' => [
                'id' => 1,
                'slug' => 'payload-division',
                'name' => 'Payload Division',
                'domains' => [],
            ],
        ]);

    $tenancy->forgetCurrentTenant();
    $tenancy->forgetCurrentLandlord();
    $tenancy->fromTenancyPayload(['landlord' => ['slug' => 'payload-division']]);

    expect($tenancy->currentTenant())->toBeNull()
        ->and($tenancy->currentLandlord()?->slug())->toBe('payload-division')
        ->and($tenancy->tenancyPayload())->toBe([
            'landlord' => [
                'id' => 1,
                'slug' => 'payload-division',
                'name' => 'Payload Division',
                'domains' => [],
            ],
        ]);
});

it('clears stale tenant and landlord context before queue job processing', function (): void {
    $landlord = Landlord::query()->create([
        'slug' => 'queue-reset-landlord',
        'name' => 'Queue Reset Landlord',
    ]);

    Tenant::query()->create([
        'slug' => 'queue-reset-tenant',
        'name' => 'Queue Reset Tenant',
        'landlord_id' => $landlord->id,
        'domains' => ['queue-reset.example.test'],
    ]);

    $tenancy = resolve(TenancyInterface::class);
    $tenancy->fromTenantPayload(['slug' => 'queue-reset-tenant']);

    expect($tenancy->currentTenant()?->slug())->toBe('queue-reset-tenant')
        ->and($tenancy->currentLandlord()?->slug())->toBe('queue-reset-landlord');

    $job = mock(Job::class);
    $job->shouldReceive('payload')->andReturn([]);

    Event::dispatch(
        new JobProcessing('sync', $job),
    );

    expect($tenancy->currentTenant())->toBeNull()
        ->and($tenancy->currentLandlord())->toBeNull();
});
