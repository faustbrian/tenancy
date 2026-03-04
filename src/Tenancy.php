<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy;

use Cline\Tenancy\Contracts\DatabaseAwareLandlordInterface;
use Cline\Tenancy\Contracts\DatabaseAwareTenantInterface;
use Cline\Tenancy\Contracts\LandlordAwareTenantInterface;
use Cline\Tenancy\Contracts\LandlordInterface;
use Cline\Tenancy\Contracts\LandlordRepositoryInterface;
use Cline\Tenancy\Contracts\LandlordResolverInterface;
use Cline\Tenancy\Contracts\LandlordTaskInterface;
use Cline\Tenancy\Contracts\TaskInterface;
use Cline\Tenancy\Contracts\TenancyInterface;
use Cline\Tenancy\Contracts\TenantInterface;
use Cline\Tenancy\Contracts\TenantRepositoryInterface;
use Cline\Tenancy\Contracts\TenantResolverInterface;
use Cline\Tenancy\Enums\IsolationMode;
use Cline\Tenancy\Events\LandlordEnded;
use Cline\Tenancy\Events\LandlordResolved;
use Cline\Tenancy\Events\LandlordResolving;
use Cline\Tenancy\Events\LandlordSwitched;
use Cline\Tenancy\Events\TenancyEnded;
use Cline\Tenancy\Events\TenantResolved;
use Cline\Tenancy\Events\TenantResolving;
use Cline\Tenancy\Events\TenantSwitched;
use Cline\Tenancy\Exceptions\InconsistentTenantLandlordContext;
use Cline\Tenancy\Exceptions\UnresolvedLandlordContext;
use Cline\Tenancy\Exceptions\UnresolvedTenantContext;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;

use function array_key_exists;
use function array_last;
use function array_pop;
use function config;
use function is_array;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Core tenancy service that manages the active tenant and landlord context stacks.
 *
 * This class is the central orchestrator for all tenant- and landlord-aware operations
 * in the package. It maintains two independent LIFO stacks — one for tenant contexts and
 * one for landlord contexts — and coordinates the lifecycle of context switches by
 * dispatching events and invoking registered `TaskInterface` / `LandlordTaskInterface`
 * implementations in sequence.
 *
 * **Context stacks** allow nested scopes: calling `runAsTenant()` inside another
 * `runAsTenant()` pushes a second context, which is popped when the inner closure
 * returns, automatically restoring the outer tenant. The same applies to landlord
 * contexts.
 *
 * **Landlord–tenant synchronisation**: when `tenancy.landlord.sync_with_tenant` is
 * enabled (the default), activating a tenant context also automatically resolves and
 * pushes the associated landlord context. The landlord identifier is read either from
 * `LandlordAwareTenantInterface::landlordId()` or from the tenant's context payload
 * using the key defined in `tenancy.landlord.payload_key`.
 *
 * **Coherence enforcement**: when `tenancy.context.enforce_coherence` is enabled (the
 * default), attempting to activate a landlord context that does not match the expected
 * landlord for the active tenant throws `InconsistentTenantLandlordContext`.
 *
 * **Queue propagation**: the `tenancyPayload()`, `fromTenancyPayload()`, and related
 * payload methods serialise and restore the active context alongside queued jobs.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class Tenancy implements TenancyInterface
{
    /**
     * LIFO stack of tenant contexts, with the active context at the tail.
     *
     * A `null` entry represents a system-level scope pushed by `runAsSystem()`,
     * which explicitly clears the tenant context for the duration of a callback.
     *
     * @var array<int, null|TenantContext>
     */
    private array $tenantStack = [];

    /**
     * LIFO stack of landlord contexts, with the active context at the tail.
     *
     * A `null` entry represents a system-level scope pushed by `runAsSystem()`,
     * which explicitly clears the landlord context for the duration of a callback.
     *
     * @var array<int, null|LandlordContext>
     */
    private array $landlordStack = [];

    /**
     * Create a new Tenancy service instance.
     *
     * @param TenantRepositoryInterface         $tenants          Repository used to look up tenants by identifier or slug.
     * @param LandlordRepositoryInterface       $landlords        Repository used to look up landlords by identifier or slug.
     * @param TenantResolverInterface           $resolver         Resolver chain used to identify the tenant from an HTTP request.
     * @param LandlordResolverInterface         $landlordResolver Resolver chain used to identify the landlord from an HTTP request.
     * @param Dispatcher                        $events           Event dispatcher used to fire context lifecycle events.
     * @param array<int, TaskInterface>         $tasks            Ordered list of tasks executed on each tenant context switch.
     * @param array<int, LandlordTaskInterface> $landlordTasks    Ordered list of tasks executed on each landlord context switch.
     */
    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly LandlordRepositoryInterface $landlords,
        private readonly TenantResolverInterface $resolver,
        private readonly LandlordResolverInterface $landlordResolver,
        private readonly Dispatcher $events,
        private readonly array $tasks = [],
        private readonly array $landlordTasks = [],
    ) {}

    /**
     * Return the currently active tenant context, or `null` when outside a tenant scope.
     */
    public function currentTenant(): ?TenantContext
    {
        return $this->tenantStack === [] ? null : array_last($this->tenantStack);
    }

    /**
     * Return the currently active landlord context, or `null` when outside a landlord scope.
     */
    public function currentLandlord(): ?LandlordContext
    {
        return $this->landlordStack === [] ? null : array_last($this->landlordStack);
    }

    /**
     * Return the active tenant's primary key, or `null` when outside a tenant scope.
     */
    public function tenantId(): int|string|null
    {
        return $this->currentTenant()?->id();
    }

    /**
     * Return the active landlord's primary key, or `null` when outside a landlord scope.
     */
    public function landlordId(): int|string|null
    {
        return $this->currentLandlord()?->id();
    }

    /**
     * Resolve the given value to a `TenantContext`, or `null` if it cannot be resolved.
     *
     * Accepts a `TenantContext` (returned as-is), a `TenantInterface` model (wrapped
     * in a new context), or an integer/string identifier (looked up via the tenant
     * repository). Returns `null` when the input is `null` or no matching tenant is found.
     *
     * @param null|int|string|TenantContext|TenantInterface $tenant The tenant to resolve.
     */
    public function tenant(TenantInterface|TenantContext|int|string|null $tenant): ?TenantContext
    {
        if ($tenant === null) {
            return null;
        }

        if ($tenant instanceof TenantContext) {
            return $tenant;
        }

        if ($tenant instanceof TenantInterface) {
            return new TenantContext($tenant);
        }

        $resolved = $this->tenants->findByIdentifier($tenant);

        return $resolved instanceof TenantInterface ? new TenantContext($resolved) : null;
    }

    /**
     * Resolve the given value to a `LandlordContext`, or `null` if it cannot be resolved.
     *
     * Accepts a `LandlordContext` (returned as-is), a `LandlordInterface` model (wrapped
     * in a new context), or an integer/string identifier (looked up via the landlord
     * repository). Returns `null` when the input is `null` or no matching landlord is found.
     *
     * @param null|int|LandlordContext|LandlordInterface|string $landlord The landlord to resolve.
     */
    public function landlord(LandlordInterface|LandlordContext|int|string|null $landlord): ?LandlordContext
    {
        if ($landlord === null) {
            return null;
        }

        if ($landlord instanceof LandlordContext) {
            return $landlord;
        }

        if ($landlord instanceof LandlordInterface) {
            return new LandlordContext($landlord);
        }

        $resolved = $this->landlords->findByIdentifier($landlord);

        return $resolved instanceof LandlordInterface ? new LandlordContext($resolved) : null;
    }

    /**
     * Look up a tenant by its URL-safe slug and return a `TenantContext`, or `null` if not found.
     *
     * @param string $slug The tenant's unique slug.
     */
    public function tenantBySlug(string $slug): ?TenantContext
    {
        $tenant = $this->tenants->findBySlug($slug);

        return $tenant instanceof TenantInterface ? new TenantContext($tenant) : null;
    }

    /**
     * Look up a landlord by its URL-safe slug and return a `LandlordContext`, or `null` if not found.
     *
     * @param string $slug The landlord's unique slug.
     */
    public function landlordBySlug(string $slug): ?LandlordContext
    {
        $landlord = $this->landlords->findBySlug($slug);

        return $landlord instanceof LandlordInterface ? new LandlordContext($landlord) : null;
    }

    /**
     * Return all tenants from the repository.
     *
     * @return iterable<TenantInterface>
     */
    public function allTenants(): iterable
    {
        return $this->tenants->all();
    }

    /**
     * Return all landlords from the repository.
     *
     * @return iterable<LandlordInterface>
     */
    public function allLandlords(): iterable
    {
        return $this->landlords->all();
    }

    /**
     * Execute a callback within the scope of the given tenant.
     *
     * Resolves the tenant to a `TenantContext`, pushes it (and its associated
     * landlord if sync is enabled) onto the context stacks, invokes the callback,
     * then pops both contexts in the `finally` block to guarantee cleanup even if
     * the callback throws.
     *
     * When the tenant cannot be resolved and `tenancy.context.require_resolved` is
     * `true`, an `UnresolvedTenantContext` exception is thrown. When the flag is
     * `false`, the callback is executed without a tenant context via `runAsSystem()`.
     *
     * @param int|string|TenantContext|TenantInterface $tenant   The tenant to scope the callback to.
     * @param callable                                 $callback The callback to execute within the tenant scope.
     *
     * @throws UnresolvedTenantContext When the tenant cannot be resolved and resolution is required.
     * @return mixed                   The return value of the callback.
     */
    public function runAsTenant(TenantInterface|TenantContext|int|string $tenant, callable $callback): mixed
    {
        $context = $this->tenant($tenant);

        if (!$context instanceof TenantContext) {
            if ($this->requiresResolvedContext()) {
                throw UnresolvedTenantContext::forIdentifier($tenant);
            }

            return $this->runAsSystem($callback);
        }

        $this->pushTenantContext($context);
        $landlordPushed = $this->pushLandlordForTenantContext($context, true);

        try {
            return $callback();
        } finally {
            $this->popTenantContext();

            if ($landlordPushed) {
                $this->popLandlordContext();
            }
        }
    }

    /**
     * Execute a callback within the scope of the given landlord.
     *
     * Resolves the landlord to a `LandlordContext`, pushes it onto the landlord
     * stack, invokes the callback, then pops the context in the `finally` block.
     *
     * When the landlord cannot be resolved and `tenancy.context.require_resolved` is
     * `true`, an `UnresolvedLandlordContext` exception is thrown. When the flag is
     * `false`, the callback is executed without a landlord context via `runAsSystem()`.
     *
     * @param int|LandlordContext|LandlordInterface|string $landlord The landlord to scope the callback to.
     * @param callable                                     $callback The callback to execute within the landlord scope.
     *
     * @throws UnresolvedLandlordContext When the landlord cannot be resolved and resolution is required.
     * @return mixed                     The return value of the callback.
     */
    public function runAsLandlord(LandlordInterface|LandlordContext|int|string $landlord, callable $callback): mixed
    {
        $context = $this->landlord($landlord);

        if (!$context instanceof LandlordContext) {
            if ($this->requiresResolvedContext()) {
                throw UnresolvedLandlordContext::forIdentifier($landlord);
            }

            return $this->runAsSystem($callback);
        }

        $this->pushLandlordContext($context);

        try {
            return $callback();
        } finally {
            $this->popLandlordContext();
        }
    }

    /**
     * Execute a callback with both tenant and landlord contexts explicitly cleared.
     *
     * Pushes `null` onto both context stacks for the duration of the callback,
     * effectively presenting a system-level (no-tenant, no-landlord) scope. Both
     * contexts are popped in the `finally` block regardless of whether the
     * callback throws.
     *
     * @param  callable $callback The callback to execute without any tenant or landlord context.
     * @return mixed    The return value of the callback.
     */
    public function runAsSystem(callable $callback): mixed
    {
        $this->pushLandlordContext(null);
        $this->pushTenantContext(null);

        try {
            return $callback();
        } finally {
            $this->popTenantContext();
            $this->popLandlordContext();
        }
    }

    /**
     * Clear the entire tenant context stack and run teardown tasks for the current tenant.
     *
     * Collapses the stack to an empty array, runs `forgetCurrent` on each registered
     * task for the previously active context, and dispatches a `TenancyEnded` event.
     * Also clears the landlord stack when `tenancy.landlord.sync_with_tenant` is enabled.
     */
    public function forgetCurrentTenant(): void
    {
        $previous = $this->currentTenant();
        $this->tenantStack = [];

        $this->switchTenantContext($previous, null);

        if ($this->shouldSyncLandlordWithTenant()) {
            $this->forgetCurrentLandlord();
        }

        $this->events->dispatch(
            new TenancyEnded($previous),
        );
    }

    /**
     * Clear the entire landlord context stack and run teardown tasks for the current landlord.
     *
     * Collapses the stack to an empty array, runs `forgetCurrent` on each registered
     * landlord task for the previously active context, and dispatches a `LandlordEnded`
     * event. Has no effect when the landlord stack is already empty.
     */
    public function forgetCurrentLandlord(): void
    {
        if ($this->landlordStack === []) {
            return;
        }

        $previous = $this->currentLandlord();
        $this->landlordStack = [];

        $this->switchLandlordContext($previous, null);

        $this->events->dispatch(
            new LandlordEnded($previous),
        );
    }

    /**
     * Return the database connection name for the active tenant.
     *
     * Prefers the connection declared on the tenant model via `DatabaseAwareTenantInterface`,
     * then falls back to the static `tenancy.database.connection` config value.
     * Returns `null` when neither source provides a connection name.
     */
    public function tenantConnection(): ?string
    {
        $tenant = $this->currentTenant()?->tenant;

        if ($tenant instanceof DatabaseAwareTenantInterface) {
            $database = $tenant->databaseConfig();

            if (is_array($database) && isset($database['connection']) && is_string($database['connection'])) {
                return $database['connection'];
            }
        }

        $connection = config('tenancy.database.connection');

        return is_string($connection) ? $connection : null;
    }

    /**
     * Return the database connection name for the active landlord.
     *
     * Prefers the connection declared on the landlord model via `DatabaseAwareLandlordInterface`,
     * then falls back to the static `tenancy.landlord.database.connection` config value.
     * Returns `null` when neither source provides a connection name.
     */
    public function landlordConnection(): ?string
    {
        $landlord = $this->currentLandlord()?->landlord;

        if ($landlord instanceof DatabaseAwareLandlordInterface) {
            $database = $landlord->databaseConfig();

            if (is_array($database) && isset($database['connection']) && is_string($database['connection'])) {
                return $database['connection'];
            }
        }

        $connection = config('tenancy.landlord.database.connection');

        return is_string($connection) ? $connection : null;
    }

    /**
     * Return the database isolation mode configured for tenants.
     *
     * Reads `tenancy.isolation` and returns the corresponding `IsolationMode` enum
     * case. Defaults to `IsolationMode::SHARED_DATABASE` when the key is absent.
     */
    public function tenantIsolation(): IsolationMode
    {
        $value = config('tenancy.isolation', IsolationMode::SHARED_DATABASE->value);

        if (!is_string($value)) {
            $value = IsolationMode::SHARED_DATABASE->value;
        }

        return IsolationMode::from($value);
    }

    /**
     * Return the database isolation mode configured for landlords.
     *
     * Reads `tenancy.landlord.isolation` and returns the corresponding `IsolationMode`
     * enum case. Defaults to `IsolationMode::SHARED_DATABASE` when the key is absent.
     */
    public function landlordIsolation(): IsolationMode
    {
        $value = config('tenancy.landlord.isolation', IsolationMode::SHARED_DATABASE->value);

        if (!is_string($value)) {
            $value = IsolationMode::SHARED_DATABASE->value;
        }

        return IsolationMode::from($value);
    }

    /**
     * Return the tenant resolver chain used to identify tenants from HTTP requests.
     */
    public function tenantResolver(): TenantResolverInterface
    {
        return $this->resolver;
    }

    /**
     * Return the landlord resolver chain used to identify landlords from HTTP requests.
     */
    public function landlordResolver(): LandlordResolverInterface
    {
        return $this->landlordResolver;
    }

    /**
     * Resolve the active tenant from an HTTP request and push its context onto the stack.
     *
     * Dispatches `TenantResolving` before invoking the resolver chain. If a tenant is
     * found, wraps it in a `TenantContext`, pushes it onto the stack (triggering tasks
     * and a `TenantSwitched` event), and — when landlord sync is enabled — resolves and
     * pushes the associated landlord. Dispatches `TenantResolved` after the context is
     * fully activated. Returns `null` when no tenant could be identified.
     *
     * @param  Request            $request The incoming HTTP request used to identify the tenant.
     * @return null|TenantContext The resolved context, or `null` if no tenant was identified.
     */
    public function resolveTenant(Request $request): ?TenantContext
    {
        $this->events->dispatch(
            new TenantResolving($request),
        );

        $tenant = $this->resolver->resolve($request);

        if (!$tenant instanceof TenantInterface) {
            return null;
        }

        $context = new TenantContext($tenant);
        $previous = $this->currentTenant();
        $this->tenantStack[] = $context;

        $this->switchTenantContext($previous, $context);
        $this->pushLandlordForTenantContext($context, true);
        $this->events->dispatch(
            new TenantResolved($context, $previous),
        );

        return $context;
    }

    /**
     * Resolve the active landlord from an HTTP request and push its context onto the stack.
     *
     * Dispatches `LandlordResolving` before invoking the resolver chain. If a landlord is
     * found, wraps it in a `LandlordContext`, pushes it onto the stack (triggering tasks
     * and a `LandlordSwitched` event), and dispatches `LandlordResolved`. Returns `null`
     * when no landlord could be identified.
     *
     * @param  Request              $request The incoming HTTP request used to identify the landlord.
     * @return null|LandlordContext The resolved context, or `null` if no landlord was identified.
     */
    public function resolveLandlord(Request $request): ?LandlordContext
    {
        $this->events->dispatch(
            new LandlordResolving($request),
        );

        $landlord = $this->landlordResolver->resolve($request);

        if (!$landlord instanceof LandlordInterface) {
            return null;
        }

        $context = new LandlordContext($landlord);
        $previous = $this->currentLandlord();

        $this->pushLandlordContext($context);

        $this->events->dispatch(
            new LandlordResolved($context, $previous),
        );

        return $context;
    }

    /**
     * Return a tenant-scoped queue name for the given queue and optional tenant.
     *
     * Builds a queue name in the form `{prefix}{delimiter}{tenantId}{delimiter}{queue}` —
     * for example `tenant:42:default`. Uses the active tenant context when no tenant
     * argument is provided. Returns the original queue name unchanged when no tenant
     * context is available.
     *
     * Configuration keys (all optional — defaults shown):
     * - `tenancy.queue.prefix`    — defaults to `"tenant"`
     * - `tenancy.queue.delimiter` — defaults to `":"`
     *
     * @param  string                                        $queue  The base queue name to scope.
     * @param  null|int|string|TenantContext|TenantInterface $tenant The tenant to scope the queue to.
     *                                                               Defaults to the currently active tenant.
     * @return string                                        The scoped queue name, or the original name when no tenant is available.
     */
    public function tenantScopedQueue(string $queue, TenantInterface|TenantContext|int|string|null $tenant = null): string
    {
        $context = $this->tenant($tenant) ?? $this->currentTenant();

        if (!$context instanceof TenantContext) {
            return $queue;
        }

        $prefix = config('tenancy.queue.prefix', 'tenant');
        $delimiter = config('tenancy.queue.delimiter', ':');

        if (!is_string($prefix) || $prefix === '') {
            $prefix = 'tenant';
        }

        if (!is_string($delimiter) || $delimiter === '') {
            $delimiter = ':';
        }

        return sprintf('%s%s%s%s%s', $prefix, $delimiter, (string) $context->id(), $delimiter, $queue);
    }

    /**
     * Return a landlord-scoped queue name for the given queue and optional landlord.
     *
     * Builds a queue name in the form `{prefix}{delimiter}{landlordId}{delimiter}{queue}` —
     * for example `landlord:7:default`. Uses the active landlord context when no landlord
     * argument is provided. Returns the original queue name unchanged when no landlord
     * context is available.
     *
     * Configuration keys (all optional — defaults shown):
     * - `tenancy.landlord.queue.prefix`    — defaults to `"landlord"`
     * - `tenancy.landlord.queue.delimiter` — defaults to `":"`
     *
     * @param  string                                            $queue    The base queue name to scope.
     * @param  null|int|LandlordContext|LandlordInterface|string $landlord The landlord to scope the queue to.
     *                                                                     Defaults to the currently active landlord.
     * @return string                                            The scoped queue name, or the original name when no landlord is available.
     */
    public function landlordScopedQueue(string $queue, LandlordInterface|LandlordContext|int|string|null $landlord = null): string
    {
        $context = $this->landlord($landlord) ?? $this->currentLandlord();

        if (!$context instanceof LandlordContext) {
            return $queue;
        }

        $prefix = config('tenancy.landlord.queue.prefix', 'landlord');
        $delimiter = config('tenancy.landlord.queue.delimiter', ':');

        if (!is_string($prefix) || $prefix === '') {
            $prefix = 'landlord';
        }

        if (!is_string($delimiter) || $delimiter === '') {
            $delimiter = ':';
        }

        return sprintf('%s%s%s%s%s', $prefix, $delimiter, (string) $context->id(), $delimiter, $queue);
    }

    /**
     * Restore the tenant (and optionally landlord) context from a serialised tenancy payload.
     *
     * Accepts either a flat tenant payload (`{id: ..., slug: ...}`) or a nested envelope
     * with explicit `tenant` and `landlord` keys. When a nested envelope is detected, each
     * sub-payload is processed independently. If a `landlord` sub-payload is present it is
     * always applied regardless of whether a tenant was resolved; this supports landlord-only
     * queue jobs. When using a flat payload, the associated landlord is auto-resolved from
     * the tenant's context if landlord sync is enabled.
     *
     * @param array<string, mixed> $payload The serialised tenancy payload from a queued job.
     */
    public function fromTenancyPayload(array $payload): void
    {
        /** @var array<string, mixed> $tenantPayload */
        $tenantPayload = $payload;

        /** @var null|array<string, mixed> $landlordPayload */
        $landlordPayload = null;
        $hasExplicitTenantPayload = false;

        if ($this->usesNestedTenancyPayloadEnvelope($payload) && isset($payload['tenant']) && is_array($payload['tenant'])) {
            /** @var array<string, mixed> $tenant */
            $tenant = $payload['tenant'];
            $tenantPayload = $tenant;
            $hasExplicitTenantPayload = true;
        }

        if ($this->usesNestedTenancyPayloadEnvelope($payload) && isset($payload['landlord']) && is_array($payload['landlord'])) {
            /** @var array<string, mixed> $landlord */
            $landlord = $payload['landlord'];
            $landlordPayload = $landlord;
        }

        $context = $this->tenantFromPayload($tenantPayload);

        if (!$context instanceof TenantContext) {
            if ($hasExplicitTenantPayload) {
                return;
            }

            if ($landlordPayload !== null) {
                $this->fromLandlordPayload($landlordPayload);
            }

            return;
        }

        $this->pushTenantContext($context);

        if ($landlordPayload !== null) {
            $this->fromLandlordPayload($landlordPayload);

            return;
        }

        $this->pushLandlordForTenantContext($context, true);
    }

    /**
     * Return the serialised payload of the currently active tenant context.
     *
     * Returns `null` when no tenant context is active.
     *
     * @return null|array<string, mixed>
     */
    public function tenantPayload(): ?array
    {
        return $this->currentTenant()?->payload();
    }

    /**
     * Restore the tenant (and its associated landlord) context from a flat tenant payload.
     *
     * Looks up the tenant from the payload's `id` or `slug` key, wraps it in a context,
     * and pushes both the tenant context and (when sync is enabled) the associated landlord
     * context onto their respective stacks. Has no effect when the tenant cannot be resolved.
     *
     * @param array<string, mixed> $payload A flat tenant payload containing at least an `id` or `slug` key.
     */
    public function fromTenantPayload(array $payload): void
    {
        $context = $this->tenantFromPayload($payload);

        if (!$context instanceof TenantContext) {
            return;
        }

        $this->pushTenantContext($context);
        $this->pushLandlordForTenantContext($context, true);
    }

    /**
     * Return the serialised payload of the currently active landlord context.
     *
     * Returns `null` when no landlord context is active.
     *
     * @return null|array<string, mixed>
     */
    public function landlordPayload(): ?array
    {
        return $this->currentLandlord()?->payload();
    }

    /**
     * Restore the landlord context from a flat landlord payload.
     *
     * Looks up the landlord from the payload's `id` or `slug` key and pushes a new
     * `LandlordContext` onto the stack. Has no effect when the landlord cannot be resolved.
     *
     * @param array<string, mixed> $payload A flat landlord payload containing at least an `id` or `slug` key.
     */
    public function fromLandlordPayload(array $payload): void
    {
        $landlord = null;

        if (isset($payload['id']) && (is_int($payload['id']) || is_string($payload['id']))) {
            $landlord = $this->landlords->findByIdentifier($payload['id']);
        }

        if (!$landlord instanceof LandlordInterface && isset($payload['slug']) && is_string($payload['slug'])) {
            $landlord = $this->landlords->findBySlug($payload['slug']);
        }

        if (!$landlord instanceof LandlordInterface) {
            return;
        }

        $this->pushLandlordContext(
            new LandlordContext($landlord),
        );
    }

    /**
     * Return a serialised representation of the active tenant and landlord contexts for queue propagation.
     *
     * The structure varies depending on which contexts are active:
     * - Both tenant and landlord active: returns `['tenant' => [...], 'landlord' => [...]]`
     * - Tenant only: returns the tenant payload directly (flat array)
     * - Landlord only: returns `['landlord' => [...]]`
     * - Neither active: returns `null`
     *
     * @return null|array<string, mixed>
     */
    public function tenancyPayload(): ?array
    {
        $tenant = $this->tenantPayload();
        $landlord = $this->landlordPayload();

        if ($tenant === null && $landlord === null) {
            return null;
        }

        if ($tenant !== null && $landlord === null) {
            return $tenant;
        }

        if ($tenant === null) {
            return ['landlord' => $landlord];
        }

        return [
            'tenant' => $tenant,
            'landlord' => $landlord,
        ];
    }

    /**
     * Push a tenant context onto the stack and run the corresponding task lifecycle.
     *
     * Captures the previous context, appends the new one, then delegates to
     * `switchTenantContext` to run `forgetCurrent` on the outgoing context and
     * `makeCurrent` on the incoming one.
     *
     * @param null|TenantContext $context The context to push, or `null` to enter a system scope.
     */
    private function pushTenantContext(?TenantContext $context): void
    {
        $previous = $this->currentTenant();
        $this->tenantStack[] = $context;

        $this->switchTenantContext($previous, $context);
    }

    /**
     * Pop the most recent tenant context from the stack and run the corresponding task lifecycle.
     *
     * Removes the tail entry, then delegates to `switchTenantContext` with the
     * popped context as `$previous` and the new tail as `$current` to run the
     * appropriate task transitions.
     */
    private function popTenantContext(): void
    {
        $previous = array_pop($this->tenantStack);
        $current = $this->currentTenant();

        $previousContext = $previous instanceof TenantContext ? $previous : null;

        $this->switchTenantContext($previousContext, $current);
    }

    /**
     * Run the task lifecycle and dispatch an event when the active tenant context changes.
     *
     * Calls `forgetCurrent` on all registered tasks for the outgoing context, then
     * `makeCurrent` for all tasks on the incoming context. Skips both task passes and
     * the event dispatch when the two contexts represent the same tenant. Always
     * dispatches a `TenantSwitched` event when a change does occur.
     *
     * @param null|TenantContext $previous The tenant context being deactivated.
     * @param null|TenantContext $current  The tenant context being activated.
     */
    private function switchTenantContext(?TenantContext $previous, ?TenantContext $current): void
    {
        if ($this->sameTenant($previous, $current)) {
            return;
        }

        if ($previous instanceof TenantContext) {
            foreach ($this->tasks as $task) {
                $task->forgetCurrent($previous);
            }
        }

        if ($current instanceof TenantContext) {
            foreach ($this->tasks as $task) {
                $task->makeCurrent($current);
            }
        }

        $this->events->dispatch(
            new TenantSwitched($previous, $current),
        );
    }

    /**
     * Push a landlord context onto the stack and run the corresponding task lifecycle.
     *
     * Captures the previous context, appends the new one, then delegates to
     * `switchLandlordContext` to run `forgetCurrent` on the outgoing context and
     * `makeCurrent` on the incoming one.
     *
     * @param null|LandlordContext $context The context to push, or `null` to enter a system scope.
     */
    private function pushLandlordContext(?LandlordContext $context): void
    {
        $previous = $this->currentLandlord();
        $this->landlordStack[] = $context;

        $this->switchLandlordContext($previous, $context);
    }

    /**
     * Pop the most recent landlord context from the stack and run the corresponding task lifecycle.
     *
     * Removes the tail entry, then delegates to `switchLandlordContext` with the
     * popped context as `$previous` and the new tail as `$current` to run the
     * appropriate task transitions.
     */
    private function popLandlordContext(): void
    {
        $previous = array_pop($this->landlordStack);
        $current = $this->currentLandlord();

        $previousContext = $previous instanceof LandlordContext ? $previous : null;

        $this->switchLandlordContext($previousContext, $current);
    }

    /**
     * Run the task lifecycle and dispatch an event when the active landlord context changes.
     *
     * Before activating the new context, validates that it is compatible with the active
     * tenant when `tenancy.context.enforce_coherence` is enabled. Calls `forgetCurrent`
     * on all registered landlord tasks for the outgoing context, then `makeCurrent` for
     * all tasks on the incoming context. Always dispatches a `LandlordSwitched` event
     * when a change occurs.
     *
     * @param null|LandlordContext $previous The landlord context being deactivated.
     * @param null|LandlordContext $current  The landlord context being activated.
     *
     * @throws InconsistentTenantLandlordContext When the incoming landlord does not match
     *                                           the expected landlord for the active tenant
     *                                           and coherence enforcement is enabled.
     */
    private function switchLandlordContext(?LandlordContext $previous, ?LandlordContext $current): void
    {
        if ($this->sameLandlord($previous, $current)) {
            return;
        }

        if ($current instanceof LandlordContext) {
            $this->assertLandlordContextCompatibleWithTenant($current);
        }

        if ($previous instanceof LandlordContext) {
            foreach ($this->landlordTasks as $task) {
                $task->forgetCurrent($previous);
            }
        }

        if ($current instanceof LandlordContext) {
            foreach ($this->landlordTasks as $task) {
                $task->makeCurrent($current);
            }
        }

        $this->events->dispatch(
            new LandlordSwitched($previous, $current),
        );
    }

    /**
     * Resolve a `TenantContext` from a flat payload array.
     *
     * Looks up the tenant by `id` first, then by `slug` as a fallback. Returns `null`
     * when neither key is present or no matching tenant is found in the repository.
     *
     * @param array<string, mixed> $payload A flat array containing an `id` and/or `slug` key.
     */
    private function tenantFromPayload(array $payload): ?TenantContext
    {
        $tenant = null;

        if (array_key_exists('id', $payload) && (is_int($payload['id']) || is_string($payload['id']))) {
            $tenant = $this->tenants->findByIdentifier($payload['id']);
        }

        if (!$tenant instanceof TenantInterface && array_key_exists('slug', $payload) && is_string($payload['slug'])) {
            $tenant = $this->tenants->findBySlug($payload['slug']);
        }

        return $tenant instanceof TenantInterface ? new TenantContext($tenant) : null;
    }

    /**
     * Determine whether two tenant contexts represent the same tenant.
     *
     * Returns `true` when both contexts are `null` or when both hold the same tenant ID.
     * Returns `false` when one is `null` and the other is not.
     *
     * @param null|TenantContext $previous The outgoing tenant context.
     * @param null|TenantContext $current  The incoming tenant context.
     */
    private function sameTenant(?TenantContext $previous, ?TenantContext $current): bool
    {
        if (!$previous instanceof TenantContext && !$current instanceof TenantContext) {
            return true;
        }

        if (!$previous instanceof TenantContext || !$current instanceof TenantContext) {
            return false;
        }

        return $previous->id() === $current->id();
    }

    /**
     * Determine whether two landlord contexts represent the same landlord.
     *
     * Returns `true` when both contexts are `null` or when both hold the same landlord ID.
     * Returns `false` when one is `null` and the other is not.
     *
     * @param null|LandlordContext $previous The outgoing landlord context.
     * @param null|LandlordContext $current  The incoming landlord context.
     */
    private function sameLandlord(?LandlordContext $previous, ?LandlordContext $current): bool
    {
        if (!$previous instanceof LandlordContext && !$current instanceof LandlordContext) {
            return true;
        }

        if (!$previous instanceof LandlordContext || !$current instanceof LandlordContext) {
            return false;
        }

        return $previous->id() === $current->id();
    }

    /**
     * Resolve and push the landlord associated with the given tenant context.
     *
     * When `tenancy.landlord.sync_with_tenant` is disabled, this method does nothing
     * and returns `false`. Otherwise, it resolves the landlord identifier from the
     * tenant (via `LandlordAwareTenantInterface` or the context payload) and pushes
     * the corresponding landlord context. When `$strict` is `true`, a `null` context
     * is pushed even if the landlord cannot be resolved, so that `forgetCurrent` can
     * pop it symmetrically. When `$strict` is `false`, nothing is pushed and `false`
     * is returned when no landlord is found.
     *
     * @param  TenantContext $context The active tenant context to resolve a landlord for.
     * @param  bool          $strict  Whether to push a `null` landlord context when no landlord is found.
     * @return bool          `true` when a context (including `null`) was pushed; `false` otherwise.
     */
    private function pushLandlordForTenantContext(TenantContext $context, bool $strict): bool
    {
        if (!$this->shouldSyncLandlordWithTenant()) {
            return false;
        }

        $identifier = $this->landlordIdentifierFromTenant($context->tenant);

        if ($identifier === null) {
            if ($strict) {
                $this->pushLandlordContext(null);

                return true;
            }

            return false;
        }

        $landlord = $this->landlord($identifier);

        if (!$landlord instanceof LandlordContext) {
            if ($strict) {
                $this->pushLandlordContext(null);

                return true;
            }

            return false;
        }

        $this->pushLandlordContext($landlord);

        return true;
    }

    /**
     * Extract the landlord identifier from the given tenant model.
     *
     * Checks whether the tenant implements `LandlordAwareTenantInterface` and calls
     * `landlordId()` directly. When the interface is not implemented, falls back to
     * reading the configured payload key from `TenantInterface::getContextPayload()`.
     * Returns `null` when no valid identifier can be found.
     *
     * @param  TenantInterface $tenant The tenant model to extract the landlord identifier from.
     * @return null|int|string The landlord identifier, or `null` if not available.
     */
    private function landlordIdentifierFromTenant(TenantInterface $tenant): int|string|null
    {
        if ($tenant instanceof LandlordAwareTenantInterface) {
            return $tenant->landlordId();
        }

        $payload = $tenant->getContextPayload();
        $payloadKey = $this->landlordPayloadKey();
        $identifier = $payload[$payloadKey] ?? null;

        return is_int($identifier) || is_string($identifier) ? $identifier : null;
    }

    /**
     * Return the payload key used to look up the landlord identifier on a tenant's context payload.
     *
     * Reads `tenancy.landlord.payload_key` from config, defaulting to `"landlord_id"` when
     * the key is absent or is not a non-empty string.
     */
    private function landlordPayloadKey(): string
    {
        $payloadKey = config('tenancy.landlord.payload_key', 'landlord_id');

        if (!is_string($payloadKey) || $payloadKey === '') {
            return 'landlord_id';
        }

        return $payloadKey;
    }

    /**
     * Determine whether the landlord context should be kept in sync with the active tenant.
     *
     * Reads `tenancy.landlord.sync_with_tenant` from config. Defaults to `true`.
     */
    private function shouldSyncLandlordWithTenant(): bool
    {
        return (bool) config('tenancy.landlord.sync_with_tenant', true);
    }

    /**
     * Determine whether an unresolvable context identifier should throw an exception.
     *
     * Reads `tenancy.context.require_resolved` from config. When `true`, passing an
     * identifier that cannot be resolved to a context in `runAsTenant()` or
     * `runAsLandlord()` throws an exception. When `false`, the callback is executed
     * without a context via `runAsSystem()`. Defaults to `true`.
     */
    private function requiresResolvedContext(): bool
    {
        return (bool) config('tenancy.context.require_resolved', true);
    }

    /**
     * Detect whether the given payload uses the nested tenancy envelope format.
     *
     * The nested envelope format uses `tenant` and/or `landlord` keys instead of
     * top-level `id`/`slug` keys. A payload is considered nested when it contains
     * at least one of `tenant` or `landlord` but neither `id` nor `slug` at the
     * top level.
     *
     * @param array<string, mixed> $payload The payload to inspect.
     */
    private function usesNestedTenancyPayloadEnvelope(array $payload): bool
    {
        if (!array_key_exists('tenant', $payload) && !array_key_exists('landlord', $payload)) {
            return false;
        }

        return !array_key_exists('id', $payload) && !array_key_exists('slug', $payload);
    }

    /**
     * Assert that the given landlord context is compatible with the currently active tenant.
     *
     * When `tenancy.context.enforce_coherence` is enabled and a tenant is active, compares
     * the expected landlord identifier (derived from the tenant) against the identifier of
     * the landlord context being activated. Throws `InconsistentTenantLandlordContext` when
     * they do not match. Has no effect when coherence enforcement is disabled, when no tenant
     * is active, or when the active tenant has no associated landlord identifier.
     *
     * @param LandlordContext $landlord The landlord context to validate.
     *
     * @throws InconsistentTenantLandlordContext When the landlord does not match the active tenant's expected landlord.
     */
    private function assertLandlordContextCompatibleWithTenant(LandlordContext $landlord): void
    {
        if (!(bool) config('tenancy.context.enforce_coherence', true)) {
            return;
        }

        $tenantContext = $this->currentTenant();

        if (!$tenantContext instanceof TenantContext) {
            return;
        }

        $expectedIdentifier = $this->landlordIdentifierFromTenant($tenantContext->tenant);

        if ($expectedIdentifier === null) {
            return;
        }

        if ((string) $expectedIdentifier === (string) $landlord->id()) {
            return;
        }

        throw InconsistentTenantLandlordContext::forIdentifiers(
            $tenantContext->id(),
            $expectedIdentifier,
            $landlord->id(),
        );
    }
}
