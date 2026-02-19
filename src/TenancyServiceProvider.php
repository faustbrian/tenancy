<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy;

use Cline\Tenancy\Console\CreateLandlordCommand;
use Cline\Tenancy\Console\CreateTenantCommand;
use Cline\Tenancy\Console\ListLandlordsCommand;
use Cline\Tenancy\Console\ListTenantsCommand;
use Cline\Tenancy\Console\MigrateCommand;
use Cline\Tenancy\Console\MigrateLandlordCommand;
use Cline\Tenancy\Console\RunLandlordCommand;
use Cline\Tenancy\Console\RunTenantCommand;
use Cline\Tenancy\Console\SeedCommand;
use Cline\Tenancy\Console\SeedLandlordCommand;
use Cline\Tenancy\Console\TestCommand;
use Cline\Tenancy\Contracts\LandlordInterface;
use Cline\Tenancy\Contracts\LandlordRepositoryInterface;
use Cline\Tenancy\Contracts\LandlordResolverInterface;
use Cline\Tenancy\Contracts\LandlordTaskInterface;
use Cline\Tenancy\Contracts\SynchronizesLandlordDomainLookupInterface;
use Cline\Tenancy\Contracts\SynchronizesTenantDomainLookupInterface;
use Cline\Tenancy\Contracts\TaskInterface;
use Cline\Tenancy\Contracts\TenancyInterface;
use Cline\Tenancy\Contracts\TenancySchedulerInterface;
use Cline\Tenancy\Contracts\TenantImpersonationManagerInterface;
use Cline\Tenancy\Contracts\TenantInterface;
use Cline\Tenancy\Contracts\TenantRepositoryInterface;
use Cline\Tenancy\Contracts\TenantResolverInterface;
use Cline\Tenancy\Enums\IsolationMode;
use Cline\Tenancy\Exceptions\InvalidTenancyIsolationModeException;
use Cline\Tenancy\Exceptions\InvalidTenancyLandlordModelException;
use Cline\Tenancy\Exceptions\InvalidTenancyPrimaryKeyTypeException;
use Cline\Tenancy\Exceptions\InvalidTenancyResolverException;
use Cline\Tenancy\Exceptions\InvalidTenancyTableNameException;
use Cline\Tenancy\Exceptions\InvalidTenancyTaskException;
use Cline\Tenancy\Exceptions\InvalidTenancyTenantModelException;
use Cline\Tenancy\Exceptions\InvalidTenancyValueException;
use Cline\Tenancy\Http\Middleware\EnsureLandlordSessionScope;
use Cline\Tenancy\Http\Middleware\EnsureTenantSessionScope;
use Cline\Tenancy\Http\Middleware\OptionalLandlord;
use Cline\Tenancy\Http\Middleware\OptionalTenant;
use Cline\Tenancy\Http\Middleware\RequireLandlord;
use Cline\Tenancy\Http\Middleware\RequireTenant;
use Cline\Tenancy\Http\Middleware\TenantImpersonation;
use Cline\Tenancy\Models\Landlord;
use Cline\Tenancy\Models\Tenant;
use Cline\Tenancy\Repositories\EloquentLandlordRepository;
use Cline\Tenancy\Repositories\EloquentTenantRepository;
use Cline\Tenancy\Resolvers\ChainLandlordResolver;
use Cline\Tenancy\Resolvers\ChainTenantResolver;
use Cline\Tenancy\Support\TenancyScheduler;
use Cline\Tenancy\Support\TenantImpersonationManager;
use Cline\VariableKeys\Enums\PrimaryKeyType;
use Cline\VariableKeys\Facades\VariableKeys;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Override;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

use function config;
use function is_a;
use function is_array;
use function is_bool;
use function is_int;
use function is_numeric;
use function is_string;
use function resolve;

/**
 * Registers and boots all tenancy package services into the Laravel application container.
 *
 * This provider extends Spatie's `PackageServiceProvider` and is responsible for:
 *
 * - **Configuration validation** (`registeringPackage`): eagerly validates the entire
 *   `tenancy` config file on every boot, throwing typed exceptions for any misconfigured
 *   value before a request is handled.
 *
 * - **Container bindings** (`registeringPackage`): registers the following singletons:
 *   - `TenantRepositoryInterface`  → `EloquentTenantRepository`
 *   - `LandlordRepositoryInterface` → `EloquentLandlordRepository`
 *   - `TenantResolverInterface`    → `ChainTenantResolver` (built from `tenancy.resolver.resolvers`)
 *   - `LandlordResolverInterface`  → `ChainLandlordResolver` (built from `tenancy.landlord.resolver.resolvers`)
 *   - `TenancyInterface`           → `Tenancy` (assembled with all configured tasks and resolvers)
 *   - `TenancySchedulerInterface`  → `TenancyScheduler`
 *   - `TenantImpersonationManagerInterface` → `TenantImpersonationManager`
 *
 * - **Variable key mapping** (`bootingPackage`): registers the configured primary key type
 *   for the tenant and landlord Eloquent models via the `VariableKeys` package.
 *
 * - **Domain lookup synchronisation** (`bootingPackage`): hooks into Eloquent `saved` and
 *   `deleted` events for the tenant and landlord models to keep the domain lookup index in
 *   sync when repositories implement the corresponding synchronisation interfaces.
 *
 * - **Route middleware** (`bootingPackage`): registers the following middleware aliases and
 *   route macros:
 *   - `tenant`            → `RequireTenant`
 *   - `tenant.optional`   → `OptionalTenant`
 *   - `tenant.session`    → `EnsureTenantSessionScope`
 *   - `landlord`          → `RequireLandlord`
 *   - `landlord.optional` → `OptionalLandlord`
 *   - `landlord.session`  → `EnsureLandlordSessionScope`
 *   - `tenant.impersonate`→ `TenantImpersonation`
 *   - `Route::tenant()`, `Route::landlord()`, `Route::tenantBound()`, `Route::landlordBound()` macros
 *
 * - **Queue propagation** (`bootingPackage`): when `tenancy.queue.propagate` is `true`,
 *   injects the active tenancy context into every dispatched job payload and restores it
 *   on `JobProcessing`. Clears the context on `JobProcessed` and `JobExceptionOccurred`.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class TenancyServiceProvider extends PackageServiceProvider
{
    /**
     * Configure the package name, config file, migration, and Artisan commands.
     *
     * Registers the following commands:
     * - `tenancy:create-tenant` / `tenancy:create-landlord`
     * - `tenancy:list-tenants` / `tenancy:list-landlords`
     * - `tenancy:migrate` / `tenancy:migrate-landlord`
     * - `tenancy:seed` / `tenancy:seed-landlord`
     * - `tenancy:run-tenant` / `tenancy:run-landlord`
     * - `tenancy:test`
     *
     * @param Package $package The Spatie package builder instance.
     */
    public function configurePackage(Package $package): void
    {
        $package
            ->name('tenancy')
            ->hasConfigFile()
            ->hasMigration('create_tenancy_tables')
            ->hasCommands([
                CreateTenantCommand::class,
                CreateLandlordCommand::class,
                ListTenantsCommand::class,
                ListLandlordsCommand::class,
                MigrateCommand::class,
                MigrateLandlordCommand::class,
                SeedCommand::class,
                SeedLandlordCommand::class,
                RunTenantCommand::class,
                RunLandlordCommand::class,
                TestCommand::class,
            ]);
    }

    /**
     * Validate configuration and register all package service bindings.
     *
     * Called by `PackageServiceProvider` before the parent `register` completes.
     * Runs `validateConfiguration()` first — any invalid config value throws a typed
     * exception and halts the boot sequence. Then registers all package singletons
     * into the container.
     */
    #[Override()]
    public function registeringPackage(): void
    {
        $this->validateConfiguration();

        $this->app->singleton(TenantRepositoryInterface::class, EloquentTenantRepository::class);
        $this->app->singleton(LandlordRepositoryInterface::class, EloquentLandlordRepository::class);

        $this->app->singleton(TenantResolverInterface::class, function (Container $container): TenantResolverInterface {
            /** @var array<int, class-string<TenantResolverInterface>> $resolverClasses */
            $resolverClasses = (array) config('tenancy.resolver.resolvers', []);

            $resolvers = [];

            foreach ($resolverClasses as $resolverClass) {
                /** @var TenantResolverInterface $resolver */
                $resolver = $container->make($resolverClass);
                $resolvers[] = $resolver;
            }

            return new ChainTenantResolver($resolvers);
        });

        $this->app->singleton(LandlordResolverInterface::class, function (Container $container): LandlordResolverInterface {
            /** @var array<int, class-string<LandlordResolverInterface>> $resolverClasses */
            $resolverClasses = (array) config('tenancy.landlord.resolver.resolvers', []);

            $resolvers = [];

            foreach ($resolverClasses as $resolverClass) {
                /** @var LandlordResolverInterface $resolver */
                $resolver = $container->make($resolverClass);
                $resolvers[] = $resolver;
            }

            return new ChainLandlordResolver($resolvers);
        });

        $this->app->singleton(TenancyInterface::class, function (Container $container): TenancyInterface {
            /** @var array<int, class-string<TaskInterface>> $taskClasses */
            $taskClasses = (array) config('tenancy.tasks', []);
            $tasks = [];

            foreach ($taskClasses as $taskClass) {
                /** @var TaskInterface $task */
                $task = $container->make($taskClass);
                $tasks[] = $task;
            }

            /** @var array<int, class-string<LandlordTaskInterface>> $landlordTaskClasses */
            $landlordTaskClasses = (array) config('tenancy.landlord.tasks', []);
            $landlordTasks = [];

            foreach ($landlordTaskClasses as $landlordTaskClass) {
                /** @var LandlordTaskInterface $landlordTask */
                $landlordTask = $container->make($landlordTaskClass);
                $landlordTasks[] = $landlordTask;
            }

            return new Tenancy(
                $container->make(TenantRepositoryInterface::class),
                $container->make(LandlordRepositoryInterface::class),
                $container->make(TenantResolverInterface::class),
                $container->make(LandlordResolverInterface::class),
                $container->make(Dispatcher::class),
                $tasks,
                $landlordTasks,
            );
        });

        $this->app->singleton(TenancySchedulerInterface::class, TenancyScheduler::class);
        $this->app->singleton(TenantImpersonationManagerInterface::class, TenantImpersonationManager::class);
    }

    /**
     * Boot package services after all providers have been registered.
     *
     * Called by `PackageServiceProvider` before the parent `boot` completes. Delegates
     * to focused private methods for each concern: variable key mapping, domain lookup
     * synchronisation hooks, route middleware aliases and macros, and queue propagation.
     */
    #[Override()]
    public function bootingPackage(): void
    {
        $this->registerVariableKeys();
        $this->registerDomainLookupSynchronization();
        $this->registerRouting();
        $this->registerQueuePropagation();
    }

    /**
     * Register the primary key type mapping for the tenant and landlord Eloquent models.
     *
     * Reads `tenancy.primary_key_type` and passes it to `VariableKeys::map()` for both
     * the configured tenant model and landlord model, enabling the variable-key package
     * to handle UUID, ULID, or integer primary keys transparently.
     */
    private function registerVariableKeys(): void
    {
        /** @var int|string $configValue */
        $configValue = config('tenancy.primary_key_type', 'id');
        $primaryKeyType = PrimaryKeyType::tryFrom($configValue) ?? PrimaryKeyType::ID;

        /** @var class-string<Model&TenantInterface> $tenantModel */
        $tenantModel = config('tenancy.tenant_model', Tenant::class);

        /** @var class-string<LandlordInterface&Model> $landlordModel */
        $landlordModel = config('tenancy.landlord_model', Landlord::class);

        VariableKeys::map([
            $tenantModel => [
                'primary_key_type' => $primaryKeyType,
            ],
            $landlordModel => [
                'primary_key_type' => $primaryKeyType,
            ],
        ]);
    }

    /**
     * Register route middleware aliases and route macros for tenant and landlord scoping.
     *
     * Aliases registered:
     * - `tenant`             → `RequireTenant`
     * - `tenant.optional`    → `OptionalTenant`
     * - `tenant.session`     → `EnsureTenantSessionScope`
     * - `landlord`           → `RequireLandlord`
     * - `landlord.optional`  → `OptionalLandlord`
     * - `landlord.session`   → `EnsureLandlordSessionScope`
     * - `tenant.impersonate` → `TenantImpersonation`
     *
     * Macros registered (only if not already defined):
     * - `Route::tenant()`        — applies the `tenant` middleware group
     * - `Route::landlord()`      — applies the `landlord` middleware group
     * - `Route::tenantBound()`   — applies `tenant` middleware and a route parameter constraint
     * - `Route::landlordBound()` — applies `landlord` middleware and a route parameter constraint
     */
    private function registerRouting(): void
    {
        Route::aliasMiddleware('tenant', RequireTenant::class);
        Route::aliasMiddleware('tenant.optional', OptionalTenant::class);
        Route::aliasMiddleware('tenant.session', EnsureTenantSessionScope::class);
        Route::aliasMiddleware('landlord', RequireLandlord::class);
        Route::aliasMiddleware('landlord.optional', OptionalLandlord::class);
        Route::aliasMiddleware('landlord.session', EnsureLandlordSessionScope::class);
        Route::aliasMiddleware('tenant.impersonate', TenantImpersonation::class);

        if (!Route::hasMacro('tenant')) {
            Route::macro('tenant', static fn () => Route::middleware('tenant'));
        }

        if (!Route::hasMacro('landlord')) {
            Route::macro('landlord', static fn () => Route::middleware('landlord'));
        }

        if (!Route::hasMacro('tenantBound')) {
            Route::macro('tenantBound', static function (?string $key = null) {
                $resolvedKey = $key;

                if (!is_string($resolvedKey) || $resolvedKey === '') {
                    $resolvedKey = config('tenancy.routing.tenant_parameter', 'tenant');
                }

                if (!is_string($resolvedKey) || $resolvedKey === '') {
                    $resolvedKey = 'tenant';
                }

                return Route::middleware('tenant')->where([$resolvedKey => '[^/]+']);
            });
        }

        if (Route::hasMacro('landlordBound')) {
            return;
        }

        Route::macro('landlordBound', static function (?string $key = null) {
            $resolvedKey = $key;

            if (!is_string($resolvedKey) || $resolvedKey === '') {
                $resolvedKey = config('tenancy.routing.landlord_parameter', 'landlord');
            }

            if (!is_string($resolvedKey) || $resolvedKey === '') {
                $resolvedKey = 'landlord';
            }

            return Route::middleware('landlord')->where([$resolvedKey => '[^/]+']);
        });
    }

    /**
     * Hook into Eloquent model events to keep domain lookup indexes in sync.
     *
     * When the tenant repository implements `SynchronizesTenantDomainLookupInterface`,
     * listens to `saved` and `deleted` events on the configured tenant model and calls
     * `syncDomainLookup` / `purgeDomainLookup` respectively. The same pattern is applied
     * to the landlord model when the landlord repository implements
     * `SynchronizesLandlordDomainLookupInterface`.
     */
    private function registerDomainLookupSynchronization(): void
    {
        /** @var class-string<Model> $tenantModel */
        $tenantModel = config('tenancy.tenant_model', Tenant::class);

        $tenantRepository = $this->app->make(TenantRepositoryInterface::class);

        if ($tenantRepository instanceof SynchronizesTenantDomainLookupInterface) {
            $tenantModel::saved(function (Model $model) use ($tenantRepository): void {
                if (!$model instanceof TenantInterface) {
                    return;
                }

                $tenantRepository->syncDomainLookup($model);
            });

            $tenantModel::deleted(function (Model $model) use ($tenantRepository): void {
                if (!$model instanceof TenantInterface) {
                    return;
                }

                $tenantRepository->purgeDomainLookup($model->id());
            });
        }

        /** @var class-string<Model> $landlordModel */
        $landlordModel = config('tenancy.landlord_model', Landlord::class);

        $landlordRepository = $this->app->make(LandlordRepositoryInterface::class);

        if (!$landlordRepository instanceof SynchronizesLandlordDomainLookupInterface) {
            return;
        }

        $landlordModel::saved(function (Model $model) use ($landlordRepository): void {
            if (!$model instanceof LandlordInterface) {
                return;
            }

            $landlordRepository->syncDomainLookup($model);
        });

        $landlordModel::deleted(function (Model $model) use ($landlordRepository): void {
            if (!$model instanceof LandlordInterface) {
                return;
            }

            $landlordRepository->purgeDomainLookup($model->id());
        });
    }

    /**
     * Validate the entire `tenancy` configuration array at boot time.
     *
     * Checks each required and optional config key for correct type and value. Throws a
     * typed exception on the first invalid value encountered, preventing the application
     * from booting with a misconfigured tenancy setup. The following keys are validated:
     *
     * - Model classes (`tenant_model`, `landlord_model`) — must be strings, implement the
     *   corresponding interface, and extend `Illuminate\Database\Eloquent\Model`.
     * - Resolver classes (`resolver.resolvers`, `landlord.resolver.resolvers`) — must
     *   implement `TenantResolverInterface` / `LandlordResolverInterface`.
     * - Task classes (`tasks`, `landlord.tasks`) — must implement `TaskInterface` /
     *   `LandlordTaskInterface`.
     * - Primary key type (`primary_key_type`) — must be a valid `PrimaryKeyType` enum value.
     * - Table names (`table_names.*`) — must be non-empty strings.
     * - Isolation modes (`isolation`, `landlord.isolation`) — must be valid `IsolationMode` values.
     * - All boolean flags (`context.*`, `landlord.sync_with_tenant`, `domain_lookup.*`, etc.)
     * - All string values (`landlord.payload_key`, session keys, cache prefixes, etc.)
     * - All integer values (`domain_lookup.cache.ttl_seconds`, `impersonation.ttl_seconds`)
     * - Config mapping arrays (`config_mapping.mappings`, `landlord.config_mapping.mappings`)
     * - Domain lists (`resolver.central_domains`, `landlord.resolver.central_domains`)
     *
     * @throws InvalidTenancyIsolationModeException  When an isolation mode value is invalid.
     * @throws InvalidTenancyLandlordModelException  When `landlord_model` is invalid.
     * @throws InvalidTenancyPrimaryKeyTypeException When `primary_key_type` is invalid.
     * @throws InvalidTenancyResolverException       When a resolver class is invalid.
     * @throws InvalidTenancyTableNameException      When a table name config value is invalid.
     * @throws InvalidTenancyTaskException           When a task class is invalid.
     * @throws InvalidTenancyTenantModelException    When `tenant_model` is invalid.
     * @throws InvalidTenancyValueException          When any other config value fails validation.
     */
    private function validateConfiguration(): void
    {
        $tenantModel = config('tenancy.tenant_model', Tenant::class);

        if (!is_string($tenantModel)) {
            throw InvalidTenancyTenantModelException::forClass('unknown');
        }

        if (!is_a($tenantModel, TenantInterface::class, true)) {
            throw InvalidTenancyTenantModelException::forClass($tenantModel);
        }

        if (!is_a($tenantModel, Model::class, true)) {
            throw InvalidTenancyTenantModelException::forClass($tenantModel);
        }

        $landlordModel = config('tenancy.landlord_model', Landlord::class);

        if (!is_string($landlordModel)) {
            throw InvalidTenancyLandlordModelException::forClass('unknown');
        }

        if (!is_a($landlordModel, LandlordInterface::class, true)) {
            throw InvalidTenancyLandlordModelException::forClass($landlordModel);
        }

        if (!is_a($landlordModel, Model::class, true)) {
            throw InvalidTenancyLandlordModelException::forClass($landlordModel);
        }

        $resolvers = (array) config('tenancy.resolver.resolvers', []);

        foreach ($resolvers as $resolver) {
            if (!is_string($resolver)) {
                throw InvalidTenancyResolverException::forClass('unknown');
            }

            if (!is_a($resolver, TenantResolverInterface::class, true)) {
                throw InvalidTenancyResolverException::forClass($resolver);
            }
        }

        $landlordResolvers = (array) config('tenancy.landlord.resolver.resolvers', []);

        foreach ($landlordResolvers as $resolver) {
            if (!is_string($resolver)) {
                throw InvalidTenancyResolverException::forClass('unknown');
            }

            if (!is_a($resolver, LandlordResolverInterface::class, true)) {
                throw InvalidTenancyResolverException::forClass($resolver);
            }
        }

        $tasks = (array) config('tenancy.tasks', []);

        foreach ($tasks as $task) {
            if (!is_string($task)) {
                throw InvalidTenancyTaskException::forClass('unknown');
            }

            if (!is_a($task, TaskInterface::class, true)) {
                throw InvalidTenancyTaskException::forClass($task);
            }
        }

        $landlordTasks = (array) config('tenancy.landlord.tasks', []);

        foreach ($landlordTasks as $task) {
            if (!is_string($task)) {
                throw InvalidTenancyTaskException::forClass('unknown');
            }

            if (!is_a($task, LandlordTaskInterface::class, true)) {
                throw InvalidTenancyTaskException::forClass($task);
            }
        }

        $primaryKeyType = config('tenancy.primary_key_type', PrimaryKeyType::ID->value);

        if (!is_string($primaryKeyType)) {
            throw InvalidTenancyPrimaryKeyTypeException::forValue('unknown');
        }

        if (PrimaryKeyType::tryFrom($primaryKeyType) === null) {
            throw InvalidTenancyPrimaryKeyTypeException::forValue($primaryKeyType);
        }

        $landlordsTable = config('tenancy.table_names.landlords', 'landlords');

        if (!is_string($landlordsTable) || $landlordsTable === '') {
            throw InvalidTenancyTableNameException::forKey('landlords', 'unknown');
        }

        $tenantsTable = config('tenancy.table_names.tenants', 'tenants');

        if (!is_string($tenantsTable) || $tenantsTable === '') {
            throw InvalidTenancyTableNameException::forKey('tenants', 'unknown');
        }

        $tenantDomainsTable = config('tenancy.table_names.tenant_domains', 'tenant_domains');

        if (!is_string($tenantDomainsTable) || $tenantDomainsTable === '') {
            throw InvalidTenancyTableNameException::forKey('tenant_domains', 'unknown');
        }

        $landlordDomainsTable = config('tenancy.table_names.landlord_domains', 'landlord_domains');

        if (!is_string($landlordDomainsTable) || $landlordDomainsTable === '') {
            throw InvalidTenancyTableNameException::forKey('landlord_domains', 'unknown');
        }

        $landlordPayloadKey = config('tenancy.landlord.payload_key', 'landlord_id');

        if (!is_string($landlordPayloadKey) || $landlordPayloadKey === '') {
            throw InvalidTenancyValueException::forKey('landlord.payload_key', 'unknown');
        }

        $sessionKey = config('tenancy.resolver.session_key', 'tenant');

        if (!is_string($sessionKey) || $sessionKey === '') {
            throw InvalidTenancyValueException::forKey('resolver.session_key', 'unknown');
        }

        $this->validateDomainList(
            config('tenancy.resolver.central_domains', []),
            'resolver.central_domains',
        );

        $userAttribute = config('tenancy.resolver.user_attribute');

        if ($userAttribute !== null && (!is_string($userAttribute) || $userAttribute === '')) {
            throw InvalidTenancyValueException::forKey('resolver.user_attribute', 'unknown');
        }

        $landlordSessionKey = config('tenancy.landlord.resolver.session_key', 'landlord');

        if (!is_string($landlordSessionKey) || $landlordSessionKey === '') {
            throw InvalidTenancyValueException::forKey('landlord.resolver.session_key', 'unknown');
        }

        $this->validateDomainList(
            config('tenancy.landlord.resolver.central_domains', []),
            'landlord.resolver.central_domains',
        );

        $landlordUserAttribute = config('tenancy.landlord.resolver.user_attribute');

        if ($landlordUserAttribute !== null && (!is_string($landlordUserAttribute) || $landlordUserAttribute === '')) {
            throw InvalidTenancyValueException::forKey('landlord.resolver.user_attribute', 'unknown');
        }

        if (!is_bool(config('tenancy.context.require_resolved', true))) {
            throw InvalidTenancyValueException::forKey('context.require_resolved', 'unknown');
        }

        if (!is_bool(config('tenancy.context.enforce_coherence', true))) {
            throw InvalidTenancyValueException::forKey('context.enforce_coherence', 'unknown');
        }

        if (!is_bool(config('tenancy.landlord.sync_with_tenant', true))) {
            throw InvalidTenancyValueException::forKey('landlord.sync_with_tenant', 'unknown');
        }

        $this->validateConfigMappings(
            config('tenancy.config_mapping.mappings', []),
            'config_mapping.mappings',
        );

        $this->validateConfigMappings(
            config('tenancy.landlord.config_mapping.mappings', []),
            'landlord.config_mapping.mappings',
        );

        if (!is_bool(config('tenancy.domain_lookup.use_table', true))) {
            throw InvalidTenancyValueException::forKey('domain_lookup.use_table', 'unknown');
        }

        if (!is_bool(config('tenancy.domain_lookup.cache.enabled', false))) {
            throw InvalidTenancyValueException::forKey('domain_lookup.cache.enabled', 'unknown');
        }

        $tenantLookupCacheTtl = config('tenancy.domain_lookup.cache.ttl_seconds', 60);

        if (!is_int($tenantLookupCacheTtl) || $tenantLookupCacheTtl < 1) {
            throw InvalidTenancyValueException::forKey('domain_lookup.cache.ttl_seconds', 'unknown');
        }

        $tenantLookupCacheStore = config('tenancy.domain_lookup.cache.store');

        if ($tenantLookupCacheStore !== null && (!is_string($tenantLookupCacheStore) || $tenantLookupCacheStore === '')) {
            throw InvalidTenancyValueException::forKey('domain_lookup.cache.store', 'unknown');
        }

        $tenantLookupCachePrefix = config('tenancy.domain_lookup.cache.prefix', 'tenancy:domain:tenant:');

        if (!is_string($tenantLookupCachePrefix) || $tenantLookupCachePrefix === '') {
            throw InvalidTenancyValueException::forKey('domain_lookup.cache.prefix', 'unknown');
        }

        if (!is_bool(config('tenancy.landlord.domain_lookup.use_table', true))) {
            throw InvalidTenancyValueException::forKey('landlord.domain_lookup.use_table', 'unknown');
        }

        if (!is_bool(config('tenancy.landlord.domain_lookup.cache.enabled', false))) {
            throw InvalidTenancyValueException::forKey('landlord.domain_lookup.cache.enabled', 'unknown');
        }

        $landlordLookupCacheTtl = config('tenancy.landlord.domain_lookup.cache.ttl_seconds', 60);

        if (!is_int($landlordLookupCacheTtl) || $landlordLookupCacheTtl < 1) {
            throw InvalidTenancyValueException::forKey('landlord.domain_lookup.cache.ttl_seconds', 'unknown');
        }

        $landlordLookupCacheStore = config('tenancy.landlord.domain_lookup.cache.store');

        if ($landlordLookupCacheStore !== null && (!is_string($landlordLookupCacheStore) || $landlordLookupCacheStore === '')) {
            throw InvalidTenancyValueException::forKey('landlord.domain_lookup.cache.store', 'unknown');
        }

        $landlordLookupCachePrefix = config('tenancy.landlord.domain_lookup.cache.prefix', 'tenancy:domain:landlord:');

        if (!is_string($landlordLookupCachePrefix) || $landlordLookupCachePrefix === '') {
            throw InvalidTenancyValueException::forKey('landlord.domain_lookup.cache.prefix', 'unknown');
        }

        if (!is_bool(config('tenancy.scoping.require_current_tenant', false))) {
            throw InvalidTenancyValueException::forKey('scoping.require_current_tenant', 'unknown');
        }

        if (!is_bool(config('tenancy.scheduler.fail_fast', true))) {
            throw InvalidTenancyValueException::forKey('scheduler.fail_fast', 'unknown');
        }

        $impersonationTtl = config('tenancy.impersonation.ttl_seconds', 300);

        if (!is_int($impersonationTtl) || $impersonationTtl < 1) {
            throw InvalidTenancyValueException::forKey('impersonation.ttl_seconds', 'unknown');
        }

        $impersonationStore = config('tenancy.impersonation.cache_store');

        if ($impersonationStore !== null && (!is_string($impersonationStore) || $impersonationStore === '')) {
            throw InvalidTenancyValueException::forKey('impersonation.cache_store', 'unknown');
        }

        $impersonationPrefix = config('tenancy.impersonation.cache_prefix', 'tenancy:impersonation:tenant:');

        if (!is_string($impersonationPrefix) || $impersonationPrefix === '') {
            throw InvalidTenancyValueException::forKey('impersonation.cache_prefix', 'unknown');
        }

        $impersonationParameter = config('tenancy.impersonation.query_parameter', 'tenant_impersonation');

        if (!is_string($impersonationParameter) || $impersonationParameter === '') {
            throw InvalidTenancyValueException::forKey('impersonation.query_parameter', 'unknown');
        }

        $tenantSessionScopeKey = config('tenancy.session.tenant_scope_key', 'tenancy.tenant_id');

        if (!is_string($tenantSessionScopeKey) || $tenantSessionScopeKey === '') {
            throw InvalidTenancyValueException::forKey('session.tenant_scope_key', 'unknown');
        }

        $landlordSessionScopeKey = config('tenancy.session.landlord_scope_key', 'tenancy.landlord_id');

        if (!is_string($landlordSessionScopeKey) || $landlordSessionScopeKey === '') {
            throw InvalidTenancyValueException::forKey('session.landlord_scope_key', 'unknown');
        }

        $sessionAbortStatus = config('tenancy.session.abort_status', 403);

        if (!is_int($sessionAbortStatus) && (!is_string($sessionAbortStatus) || !is_numeric($sessionAbortStatus))) {
            throw InvalidTenancyValueException::forKey('session.abort_status', 'unknown');
        }

        if (!is_bool(config('tenancy.session.invalidate_on_mismatch', true))) {
            throw InvalidTenancyValueException::forKey('session.invalidate_on_mismatch', 'unknown');
        }

        $tenantForeignKey = config('tenancy.scoping.tenant_foreign_key', 'tenant_id');

        if (!is_string($tenantForeignKey) || $tenantForeignKey === '') {
            throw InvalidTenancyValueException::forKey('scoping.tenant_foreign_key', 'unknown');
        }

        $tenantRouteParameter = config('tenancy.routing.tenant_parameter', 'tenant');

        if (!is_string($tenantRouteParameter) || $tenantRouteParameter === '') {
            throw InvalidTenancyValueException::forKey('routing.tenant_parameter', 'unknown');
        }

        $landlordRouteParameter = config('tenancy.routing.landlord_parameter', 'landlord');

        if (!is_string($landlordRouteParameter) || $landlordRouteParameter === '') {
            throw InvalidTenancyValueException::forKey('routing.landlord_parameter', 'unknown');
        }

        $isolation = config('tenancy.isolation', IsolationMode::SHARED_DATABASE->value);

        if (!is_string($isolation)) {
            throw InvalidTenancyIsolationModeException::forValue('unknown');
        }

        if (IsolationMode::tryFrom($isolation) === null) {
            throw InvalidTenancyIsolationModeException::forValue($isolation);
        }

        $landlordIsolation = config('tenancy.landlord.isolation', IsolationMode::SHARED_DATABASE->value);

        if (!is_string($landlordIsolation)) {
            throw InvalidTenancyIsolationModeException::forValue('unknown');
        }

        if (IsolationMode::tryFrom($landlordIsolation) === null) {
            throw InvalidTenancyIsolationModeException::forValue($landlordIsolation);
        }
    }

    /**
     * Assert that a config mapping array contains only non-empty string key-value pairs.
     *
     * Used to validate `tenancy.config_mapping.mappings` and
     * `tenancy.landlord.config_mapping.mappings`. Throws `InvalidTenancyValueException`
     * when the value is not an array or contains any non-string or empty entry.
     *
     * @param mixed  $mappings The value read from config.
     * @param string $key      The dot-notation config key, used in the exception message.
     *
     * @throws InvalidTenancyValueException When `$mappings` is not a valid string-to-string array.
     */
    private function validateConfigMappings(mixed $mappings, string $key): void
    {
        if (!is_array($mappings)) {
            throw InvalidTenancyValueException::forKey($key, 'unknown');
        }

        foreach ($mappings as $targetKey => $sourceKey) {
            if (!is_string($targetKey) || $targetKey === '') {
                throw InvalidTenancyValueException::forKey($key, 'unknown');
            }

            if (!is_string($sourceKey) || $sourceKey === '') {
                throw InvalidTenancyValueException::forKey($key, 'unknown');
            }
        }
    }

    /**
     * Assert that a config domain list contains only non-empty strings.
     *
     * Used to validate `tenancy.resolver.central_domains` and
     * `tenancy.landlord.resolver.central_domains`. Throws `InvalidTenancyValueException`
     * when the value is not an array or contains any non-string or empty entry.
     *
     * @param mixed  $domains The value read from config.
     * @param string $key     The dot-notation config key, used in the exception message.
     *
     * @throws InvalidTenancyValueException When `$domains` is not a valid list of non-empty strings.
     */
    private function validateDomainList(mixed $domains, string $key): void
    {
        if (!is_array($domains)) {
            throw InvalidTenancyValueException::forKey($key, 'unknown');
        }

        foreach ($domains as $domain) {
            if (!is_string($domain) || $domain === '') {
                throw InvalidTenancyValueException::forKey($key, 'unknown');
            }
        }
    }

    /**
     * Register queue payload injection and context restoration for job propagation.
     *
     * When `tenancy.queue.propagate` is `true`, hooks into three queue events:
     *
     * - **`Queue::createPayloadUsing`**: injects the serialised tenancy context into every
     *   dispatched job payload under the `tenancy` key.
     * - **`JobProcessing`**: clears any existing context, reads the `tenancy` key from the
     *   job payload, and restores the tenant/landlord context via `fromTenancyPayload()`.
     * - **`JobProcessed` / `JobExceptionOccurred`**: clears the tenant and landlord context
     *   after the job completes or fails, preventing context leakage between jobs on the
     *   same worker process.
     */
    private function registerQueuePropagation(): void
    {
        if (!(bool) config('tenancy.queue.propagate', true)) {
            return;
        }

        Queue::createPayloadUsing(static function (): array {
            $payload = resolve(TenancyInterface::class)->tenancyPayload();

            return $payload === null ? [] : ['tenancy' => $payload];
        });

        Event::listen(JobProcessing::class, static function (JobProcessing $event): void {
            $tenancy = resolve(TenancyInterface::class);
            $tenancy->forgetCurrentTenant();
            $tenancy->forgetCurrentLandlord();

            $payload = $event->job->payload();
            $tenancyPayload = $payload['tenancy'] ?? null;

            if (!is_array($tenancyPayload)) {
                return;
            }

            /** @var array<string, mixed> $tenancyPayload */
            $tenancy->fromTenancyPayload($tenancyPayload);
        });

        $reset = static function (): void {
            resolve(TenancyInterface::class)->forgetCurrentTenant();
            resolve(TenancyInterface::class)->forgetCurrentLandlord();
        };

        Event::listen(JobProcessed::class, $reset);
        Event::listen(JobExceptionOccurred::class, $reset);
    }
}
