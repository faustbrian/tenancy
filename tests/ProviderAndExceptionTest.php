<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Tenancy\Contracts\LandlordInterface;
use Cline\Tenancy\Contracts\LandlordRepositoryInterface;
use Cline\Tenancy\Contracts\SynchronizesLandlordDomainLookupInterface;
use Cline\Tenancy\Contracts\SynchronizesTenantDomainLookupInterface;
use Cline\Tenancy\Contracts\TenantInterface;
use Cline\Tenancy\Contracts\TenantRepositoryInterface;
use Cline\Tenancy\Exceptions\InvalidTenancyConfiguration;
use Cline\Tenancy\Exceptions\InvalidTenancyIsolationModeException;
use Cline\Tenancy\Exceptions\InvalidTenancyLandlordModelException;
use Cline\Tenancy\Exceptions\InvalidTenancyPrimaryKeyTypeException;
use Cline\Tenancy\Exceptions\InvalidTenancyResolverException;
use Cline\Tenancy\Exceptions\InvalidTenancyTableNameException;
use Cline\Tenancy\Exceptions\InvalidTenancyTaskException;
use Cline\Tenancy\Exceptions\InvalidTenancyTenantModelException;
use Cline\Tenancy\Exceptions\InvalidTenancyValueException;
use Cline\Tenancy\Exceptions\LandlordNotResolved;
use Cline\Tenancy\Exceptions\TenantNotResolved;
use Cline\Tenancy\Exceptions\UnresolvedLandlordContext;
use Cline\Tenancy\Exceptions\UnresolvedTenantContext;
use Cline\Tenancy\Models\Landlord;
use Cline\Tenancy\Models\Tenant;
use Cline\Tenancy\Resolvers\DomainTenantResolver;
use Cline\Tenancy\Support\TenancyScheduler;
use Cline\Tenancy\Tasks\PrefixCacheTask;
use Cline\Tenancy\TenancyServiceProvider;
use Cline\VariableKeys\Enums\PrimaryKeyType;
use Cline\VariableKeys\Facades\VariableKeys;
use Illuminate\Support\Facades\Route;
use Tests\Exceptions\NotImplementedTestStubException;

use function Cline\Tenancy\tenancy_scheduler;

final class NonEloquentTenant implements TenantInterface
{
    public function id(): int
    {
        return 1;
    }

    public function slug(): string
    {
        return 'non-eloquent-tenant';
    }

    public function name(): string
    {
        return 'Non Eloquent Tenant';
    }

    public function domains(): array
    {
        return ['non-eloquent-tenant.example.test'];
    }

    public function features(): array
    {
        return [];
    }

    public function getContextPayload(): array
    {
        return [];
    }

    public function databaseConfig(): ?array
    {
        return null;
    }
}

final class NonEloquentLandlord implements LandlordInterface
{
    public function id(): int
    {
        return 1;
    }

    public function slug(): string
    {
        return 'non-eloquent-landlord';
    }

    public function name(): string
    {
        return 'Non Eloquent Landlord';
    }

    public function getContextPayload(): array
    {
        return [];
    }

    public function databaseConfig(): ?array
    {
        return null;
    }
}

final class RecordingTenantRepository implements SynchronizesTenantDomainLookupInterface, TenantRepositoryInterface
{
    /** @var array<int, int|string> */
    public array $synced = [];

    /** @var array<int, int|string> */
    public array $purged = [];

    public function findById(int|string $id): ?TenantInterface
    {
        return null;
    }

    public function findBySlug(string $slug): ?TenantInterface
    {
        return null;
    }

    public function findByDomain(string $domain): ?TenantInterface
    {
        return null;
    }

    public function findByIdentifier(int|string $identifier): ?TenantInterface
    {
        return null;
    }

    public function all(): iterable
    {
        yield from [];
    }

    public function create(array $attributes): TenantInterface
    {
        throw NotImplementedTestStubException::forMethod('create');
    }

    public function syncDomainLookup(TenantInterface $tenant): void
    {
        $this->synced[] = $tenant->id();
    }

    public function purgeDomainLookup(int|string $tenantId): void
    {
        $this->purged[] = $tenantId;
    }
}

final class RecordingLandlordRepository implements LandlordRepositoryInterface, SynchronizesLandlordDomainLookupInterface
{
    /** @var array<int, int|string> */
    public array $synced = [];

    /** @var array<int, int|string> */
    public array $purged = [];

    public function findById(int|string $id): ?LandlordInterface
    {
        return null;
    }

    public function findBySlug(string $slug): ?LandlordInterface
    {
        return null;
    }

    public function findByDomain(string $domain): ?LandlordInterface
    {
        return null;
    }

    public function findByIdentifier(int|string $identifier): ?LandlordInterface
    {
        return null;
    }

    public function all(): iterable
    {
        yield from [];
    }

    public function create(array $attributes): LandlordInterface
    {
        throw NotImplementedTestStubException::forMethod('create');
    }

    public function syncDomainLookup(LandlordInterface $landlord): void
    {
        $this->synced[] = $landlord->id();
    }

    public function purgeDomainLookup(int|string $landlordId): void
    {
        $this->purged[] = $landlordId;
    }
}

it('covers exception factory messages', function (): void {
    expect(InvalidTenancyResolverException::forClass('BadResolver')->getMessage())
        ->toContain('BadResolver')
        ->and(InvalidTenancyTaskException::forClass('BadTask')->getMessage())->toContain('BadTask')
        ->and(InvalidTenancyIsolationModeException::forValue('bad')->getMessage())->toContain('bad')
        ->and(InvalidTenancyPrimaryKeyTypeException::forValue('bad')->getMessage())->toContain('bad')
        ->and(InvalidTenancyTableNameException::forKey('tenants', 'bad')->getMessage())->toContain('bad')
        ->and(InvalidTenancyValueException::forKey('context.require_resolved', 'bad')->getMessage())->toContain('bad')
        ->and(UnresolvedTenantContext::forIdentifier(
            new stdClass(),
        )->getMessage())->toContain('Unable to resolve tenant context.')
        ->and(UnresolvedLandlordContext::forIdentifier(
            new stdClass(),
        )->getMessage())->toContain('Unable to resolve landlord context.')
        ->and(InvalidTenancyTenantModelException::forClass('BadModel')->getMessage())->toContain('BadModel')
        ->and(InvalidTenancyLandlordModelException::forClass('BadModel')->getMessage())->toContain('BadModel')
        ->and(TenantNotResolved::forHost('foo.test')->getMessage())->toContain('foo.test')
        ->and(LandlordNotResolved::forHost('bar.test')->getMessage())->toContain('bar.test');
});

it('registers helpers, scheduler and route macros', function (): void {
    /** @var TenancyServiceProvider $provider */
    $provider = app()->getProvider(TenancyServiceProvider::class);
    expect($provider)->not->toBeNull();

    new ReflectionMethod($provider, 'registerVariableKeys')->invoke($provider);
    expect(VariableKeys::isRegistered(Tenant::class))->toBeTrue();

    Tenant::query()->create(['slug' => 'scheduler-a', 'name' => 'A', 'domains' => ['a.example.test']]);
    Tenant::query()->create(['slug' => 'scheduler-b', 'name' => 'B', 'domains' => ['b.example.test']]);
    Landlord::query()->create(['slug' => 'division-a', 'name' => 'Division A']);
    Landlord::query()->create(['slug' => 'division-b', 'name' => 'Division B']);

    expect(tenancy_scheduler())->toBeInstanceOf(TenancyScheduler::class)
        ->and(Route::hasMacro('tenant'))->toBeTrue()
        ->and(Route::hasMacro('tenantBound'))->toBeTrue()
        ->and(Route::hasMacro('landlord'))->toBeTrue()
        ->and(Route::hasMacro('landlordBound'))->toBeTrue();

    $seen = [];
    $landlordSeen = [];

    tenancy_scheduler()->eachTenant(static function (Tenant $tenant) use (&$seen): void {
        $seen[] = $tenant->slug();
    });

    tenancy_scheduler()->eachLandlord(static function (Landlord $landlord) use (&$landlordSeen): void {
        $landlordSeen[] = $landlord->slug();
    });

    expect($seen)->toContain('scheduler-a')->toContain('scheduler-b')
        ->and($landlordSeen)->toContain('division-a')->toContain('division-b');
});

it('validates tenancy configuration branches', function (): void {
    /** @var TenancyServiceProvider $provider */
    $provider = app()->getProvider(TenancyServiceProvider::class);
    expect($provider)->not->toBeNull();

    $method = new ReflectionMethod($provider, 'validateConfiguration');

    config()->set('tenancy.tenant_model', ['invalid']);

    expect(fn (): mixed => $method->invoke($provider))->toThrow(InvalidTenancyConfiguration::class);

    config()->set('tenancy.tenant_model', Tenant::class);
    config()->set('tenancy.landlord_model', Landlord::class);
    config()->set('tenancy.resolver.resolvers', [['bad']]);

    expect(fn (): mixed => $method->invoke($provider))->toThrow(InvalidTenancyConfiguration::class);

    config()->set('tenancy.resolver.resolvers', [DomainTenantResolver::class]);
    config()->set('tenancy.tasks', [['bad']]);

    expect(fn (): mixed => $method->invoke($provider))->toThrow(InvalidTenancyConfiguration::class);

    config()->set('tenancy.tasks', [PrefixCacheTask::class]);
    config()->set('tenancy.isolation', ['bad']);

    expect(fn (): mixed => $method->invoke($provider))->toThrow(InvalidTenancyConfiguration::class);

    config()->set('tenancy.isolation', 'shared_database');
    config()->set('tenancy.landlord_model', ['bad']);

    expect(fn (): mixed => $method->invoke($provider))->toThrow(InvalidTenancyConfiguration::class);

    config()->set('tenancy.landlord_model', Landlord::class);
    config()->set('tenancy.primary_key_type', 'invalid');

    expect(fn (): mixed => $method->invoke($provider))->toThrow(InvalidTenancyConfiguration::class);

    config()->set('tenancy.primary_key_type', 'id');
    config()->set('tenancy.context.require_resolved', 'invalid');

    expect(fn (): mixed => $method->invoke($provider))->toThrow(InvalidTenancyConfiguration::class);

    config()->set('tenancy.context.require_resolved', true);
    config()->set('tenancy.scoping.tenant_foreign_key', ['invalid']);

    expect(fn (): mixed => $method->invoke($provider))->toThrow(InvalidTenancyConfiguration::class);

    config()->set('tenancy.scoping.tenant_foreign_key', 'tenant_id');
    config()->set('tenancy.scheduler.fail_fast', 'invalid');

    expect(fn (): mixed => $method->invoke($provider))->toThrow(InvalidTenancyConfiguration::class);

    config()->set('tenancy.scheduler.fail_fast', true);
    config()->set('tenancy.impersonation.ttl_seconds', 0);

    expect(fn (): mixed => $method->invoke($provider))->toThrow(InvalidTenancyConfiguration::class);

    config()->set('tenancy.impersonation.ttl_seconds', 300);
    config()->set('tenancy.resolver.central_domains', ['']);

    expect(fn (): mixed => $method->invoke($provider))->toThrow(InvalidTenancyConfiguration::class);

    config()->set('tenancy.resolver.central_domains', ['app.example.test']);
    config()->set('tenancy.landlord.resolver.central_domains', [1]);

    expect(fn (): mixed => $method->invoke($provider))->toThrow(InvalidTenancyConfiguration::class);

    config()->set('tenancy.landlord.resolver.central_domains', ['admin.example.test']);
    config()->set('tenancy.domain_lookup.cache.ttl_seconds', 0);

    expect(fn (): mixed => $method->invoke($provider))->toThrow(InvalidTenancyConfiguration::class);

    config()->set('tenancy.domain_lookup.cache.ttl_seconds', 60);
    config()->set('tenancy.landlord.domain_lookup.cache.prefix', '');

    expect(fn (): mixed => $method->invoke($provider))->toThrow(InvalidTenancyConfiguration::class);

    config()->set('tenancy.landlord.domain_lookup.cache.prefix', 'tenancy:domain:landlord:');
    config()->set('tenancy.impersonation.ttl_seconds', 300);
    config()->set('tenancy.impersonation.cache_prefix', '');

    expect(fn (): mixed => $method->invoke($provider))->toThrow(InvalidTenancyConfiguration::class);

    config()->set('tenancy.impersonation.cache_prefix', 'tenancy:impersonation:tenant:');
    config()->set('tenancy.session.tenant_scope_key', '');

    expect(fn (): mixed => $method->invoke($provider))->toThrow(InvalidTenancyConfiguration::class);

    config()->set('tenancy.session.tenant_scope_key', 'tenancy.tenant_id');
    config()->set('tenancy.session.abort_status', []);

    expect(fn (): mixed => $method->invoke($provider))->toThrow(InvalidTenancyConfiguration::class);

    config()->set('tenancy.session.abort_status', 403);
    config()->set('tenancy.config_mapping.mappings', ['app.name' => ['bad']]);

    expect(fn (): mixed => $method->invoke($provider))->toThrow(InvalidTenancyConfiguration::class);

    config()->set('tenancy.config_mapping.mappings', ['app.name' => 'name']);
    config()->set('tenancy.landlord.config_mapping.mappings', ['app.name' => ['bad']]);

    expect(fn (): mixed => $method->invoke($provider))->toThrow(InvalidTenancyConfiguration::class);

    config()->set('tenancy.landlord.config_mapping.mappings', ['app.name' => 'name']);
    config()->set('tenancy.tenant_model', NonEloquentTenant::class);

    expect(fn (): mixed => $method->invoke($provider))->toThrow(InvalidTenancyConfiguration::class);

    config()->set('tenancy.tenant_model', Tenant::class);
    config()->set('tenancy.landlord_model', NonEloquentLandlord::class);

    expect(fn (): mixed => $method->invoke($provider))->toThrow(InvalidTenancyConfiguration::class);
});

it('registers domain synchronization for custom repositories via contracts', function (): void {
    /** @var TenancyServiceProvider $provider */
    $provider = app()->getProvider(TenancyServiceProvider::class);
    expect($provider)->not->toBeNull();

    $tenantRepository = new RecordingTenantRepository();
    $landlordRepository = new RecordingLandlordRepository();

    app()->instance(TenantRepositoryInterface::class, $tenantRepository);
    app()->instance(LandlordRepositoryInterface::class, $landlordRepository);

    $method = new ReflectionMethod($provider, 'registerDomainLookupSynchronization');
    $method->invoke($provider);

    $tenant = Tenant::query()->create([
        'slug' => 'sync-contract-tenant',
        'name' => 'Sync Contract Tenant',
        'domains' => ['sync-contract-tenant.example.test'],
    ]);

    $tenant->update([
        'domains' => ['sync-contract-tenant-next.example.test'],
    ]);
    $tenant->delete();

    $landlord = Landlord::query()->create([
        'slug' => 'sync-contract-landlord',
        'name' => 'Sync Contract Landlord',
        'domains' => ['sync-contract-landlord.example.test'],
    ]);

    $landlord->update([
        'domains' => ['sync-contract-landlord-next.example.test'],
    ]);
    $landlord->delete();

    expect($tenantRepository->synced)->not->toBeEmpty()
        ->and($tenantRepository->purged)->not->toBeEmpty()
        ->and($landlordRepository->synced)->not->toBeEmpty()
        ->and($landlordRepository->purged)->not->toBeEmpty();
});

it('registers variable keys for tenancy models', function (): void {
    /** @var TenancyServiceProvider $provider */
    $provider = app()->getProvider(TenancyServiceProvider::class);
    expect($provider)->not->toBeNull();

    config()->set('tenancy.primary_key_type', 'uuid');

    $method = new ReflectionMethod($provider, 'registerVariableKeys');
    $method->invoke($provider);

    expect(VariableKeys::getPrimaryKeyType(Tenant::class))->toBe(PrimaryKeyType::UUID)
        ->and(VariableKeys::getPrimaryKeyType(Landlord::class))->toBe(PrimaryKeyType::UUID);
});
