<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Contracts;

use Cline\Tenancy\Enums\IsolationMode;
use Cline\Tenancy\LandlordContext;
use Cline\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Central contract for the multi-tenancy layer.
 *
 * `TenancyInterface` is the primary entry point for all tenancy operations.
 * It manages a stack-based tenant and landlord context so that nested context
 * switches — for example, during queue processing or scheduled commands — are
 * isolated and correctly restored when the scope closes.
 *
 * Resolving, switching, querying, and clearing both tenant and landlord
 * contexts are all exposed through this contract. The concrete implementation
 * is bound in the service container and accessible via the `Tenancy` facade.
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface TenancyInterface
{
    /**
     * Return the currently active tenant context.
     *
     * @return null|TenantContext The active context, or `null` if no tenant is set.
     */
    public function currentTenant(): ?TenantContext;

    /**
     * Return the currently active landlord context.
     *
     * @return null|LandlordContext The active context, or `null` if no landlord is set.
     */
    public function currentLandlord(): ?LandlordContext;

    /**
     * Return the primary key of the currently active tenant.
     *
     * Convenience shorthand for `currentTenant()?->id()`.
     *
     * @return null|int|string The active tenant's id, or `null` if no tenant is set.
     */
    public function tenantId(): int|string|null;

    /**
     * Return the primary key of the currently active landlord.
     *
     * Convenience shorthand for `currentLandlord()?->id()`.
     *
     * @return null|int|string The active landlord's id, or `null` if no landlord is set.
     */
    public function landlordId(): int|string|null;

    /**
     * Resolve the given value to a `TenantContext` without making it active.
     *
     * Accepts a `TenantInterface` model, an existing `TenantContext`, or a
     * raw identifier (id or slug). Returns `null` when `$tenant` is `null` or
     * when no matching tenant can be found in the repository.
     *
     * @param  null|int|string|TenantContext|TenantInterface $tenant The tenant to resolve.
     * @return null|TenantContext                            The resolved context, or `null` if unresolvable.
     */
    public function tenant(TenantInterface|TenantContext|int|string|null $tenant): ?TenantContext;

    /**
     * Resolve the given value to a `LandlordContext` without making it active.
     *
     * Accepts a `LandlordInterface` model, an existing `LandlordContext`, or a
     * raw identifier (id or slug). Returns `null` when `$landlord` is `null`
     * or when no matching landlord can be found in the repository.
     *
     * @param  null|int|LandlordContext|LandlordInterface|string $landlord The landlord to resolve.
     * @return null|LandlordContext                              The resolved context, or `null` if unresolvable.
     */
    public function landlord(LandlordInterface|LandlordContext|int|string|null $landlord): ?LandlordContext;

    /**
     * Resolve a tenant context by its URL-safe slug.
     *
     * @param  string             $slug The tenant's unique slug.
     * @return null|TenantContext The resolved context, or `null` if not found.
     */
    public function tenantBySlug(string $slug): ?TenantContext;

    /**
     * Resolve a landlord context by its URL-safe slug.
     *
     * @param  string               $slug The landlord's unique slug.
     * @return null|LandlordContext The resolved context, or `null` if not found.
     */
    public function landlordBySlug(string $slug): ?LandlordContext;

    /**
     * Return all tenants in the system.
     *
     * Used by the scheduler, Artisan commands, and queue workers to iterate
     * over every tenant, for example to run migrations or cron tasks in each
     * tenant context.
     *
     * @return iterable<TenantInterface> All persisted tenants.
     */
    public function allTenants(): iterable;

    /**
     * Return all landlords in the system.
     *
     * @return iterable<LandlordInterface> All persisted landlords.
     */
    public function allLandlords(): iterable;

    /**
     * Execute a callback within the given tenant's context.
     *
     * Pushes the resolved tenant context onto the stack, runs all registered
     * `TaskInterface` implementations, and executes `$callback`. The context
     * is always popped and tasks are reversed in a `finally` block, even if
     * the callback throws.
     *
     * @param  int|string|TenantContext|TenantInterface $tenant   The tenant to activate.
     * @param  callable                                 $callback The work to perform inside the tenant context.
     * @return mixed                                    The value returned by `$callback`.
     */
    public function runAsTenant(TenantInterface|TenantContext|int|string $tenant, callable $callback): mixed;

    /**
     * Execute a callback within the given landlord's context.
     *
     * Pushes the resolved landlord context onto the stack, runs all registered
     * `LandlordTaskInterface` implementations, and executes `$callback`. The
     * context is always popped and tasks are reversed in a `finally` block,
     * even if the callback throws.
     *
     * @param  int|LandlordContext|LandlordInterface|string $landlord The landlord to activate.
     * @param  callable                                     $callback The work to perform inside the landlord context.
     * @return mixed                                        The value returned by `$callback`.
     */
    public function runAsLandlord(LandlordInterface|LandlordContext|int|string $landlord, callable $callback): mixed;

    /**
     * Execute a callback outside of any tenant or landlord context.
     *
     * Pushes `null` onto both stacks so that any context-sensitive code inside
     * the callback operates against the system (landlord) database. The null
     * contexts are popped in a `finally` block after the callback completes.
     *
     * @param  callable $callback The work to perform without a tenant or landlord context.
     * @return mixed    The value returned by `$callback`.
     */
    public function runAsSystem(callable $callback): mixed;

    /**
     * Pop the current tenant context off the stack and reverse its tasks.
     */
    public function forgetCurrentTenant(): void;

    /**
     * Pop the current landlord context off the stack and reverse its tasks.
     */
    public function forgetCurrentLandlord(): void;

    /**
     * Return the configured database connection name for tenant isolation.
     *
     * @return null|string The connection name, or `null` when using the default connection.
     */
    public function tenantConnection(): ?string;

    /**
     * Return the configured database connection name for landlord isolation.
     *
     * @return null|string The connection name, or `null` when using the default connection.
     */
    public function landlordConnection(): ?string;

    /**
     * Return the isolation mode configured for tenants.
     *
     * @return IsolationMode The active isolation strategy (shared database, separate schema, or separate database).
     */
    public function tenantIsolation(): IsolationMode;

    /**
     * Return the isolation mode configured for landlords.
     *
     * @return IsolationMode The active isolation strategy (shared database, separate schema, or separate database).
     */
    public function landlordIsolation(): IsolationMode;

    /**
     * Return the resolver responsible for identifying tenants from HTTP requests.
     *
     * @return TenantResolverInterface The configured tenant resolver.
     */
    public function tenantResolver(): TenantResolverInterface;

    /**
     * Return the resolver responsible for identifying landlords from HTTP requests.
     *
     * @return LandlordResolverInterface The configured landlord resolver.
     */
    public function landlordResolver(): LandlordResolverInterface;

    /**
     * Attempt to resolve and activate the tenant for the incoming request.
     *
     * Delegates to the configured `TenantResolverInterface`. If resolution
     * succeeds, the tenant context is pushed onto the stack and tasks are run.
     * Returns `null` when the resolver cannot identify a tenant.
     *
     * @param  Request            $request The current HTTP request.
     * @return null|TenantContext The activated tenant context, or `null` if not resolved.
     */
    public function resolveTenant(Request $request): ?TenantContext;

    /**
     * Attempt to resolve and activate the landlord for the incoming request.
     *
     * Delegates to the configured `LandlordResolverInterface`. If resolution
     * succeeds, the landlord context is pushed onto the stack and tasks are
     * run. Returns `null` when the resolver cannot identify a landlord.
     *
     * @param  Request              $request The current HTTP request.
     * @return null|LandlordContext The activated landlord context, or `null` if not resolved.
     */
    public function resolveLandlord(Request $request): ?LandlordContext;

    /**
     * Return a queue name scoped to the given tenant.
     *
     * Prefixes or suffixes the base `$queue` name with the tenant's identifier
     * so that queue workers can process jobs for a single tenant in isolation.
     * When `$tenant` is `null`, the currently active tenant is used.
     *
     * @param  string                                        $queue  The base queue name.
     * @param  null|int|string|TenantContext|TenantInterface $tenant The tenant to scope to, or `null` to use the current tenant.
     * @return string                                        The tenant-scoped queue name.
     */
    public function tenantScopedQueue(string $queue, TenantInterface|TenantContext|int|string|null $tenant = null): string;

    /**
     * Return a queue name scoped to the given landlord.
     *
     * Prefixes or suffixes the base `$queue` name with the landlord's
     * identifier so that queue workers can process jobs for a single landlord
     * in isolation. When `$landlord` is `null`, the currently active landlord
     * is used.
     *
     * @param  string                                            $queue    The base queue name.
     * @param  null|int|LandlordContext|LandlordInterface|string $landlord The landlord to scope to, or `null` to use the current landlord.
     * @return string                                            The landlord-scoped queue name.
     */
    public function landlordScopedQueue(string $queue, LandlordInterface|LandlordContext|int|string|null $landlord = null): string;

    /**
     * Restore both the tenant and landlord context from a combined tenancy payload.
     *
     * Used by queue workers and other asynchronous consumers to reinstate the
     * full tenancy context that was serialised when a job was dispatched.
     *
     * @param array<string, mixed> $payload The serialised tenancy payload, as produced by `tenancyPayload()`.
     */
    public function fromTenancyPayload(array $payload): void;

    /**
     * Return the serialised payload representing both the current tenant and landlord.
     *
     * Combines the outputs of `tenantPayload()` and `landlordPayload()` into a
     * single array suitable for storing alongside a queued job.
     *
     * @return null|array<string, mixed> The combined context payload, or `null` if neither context is active.
     */
    public function tenancyPayload(): ?array;

    /**
     * Restore the tenant context from a serialised tenant payload.
     *
     * @param array<string, mixed> $payload A tenant payload previously produced by `TenantContext::payload()`.
     */
    public function fromTenantPayload(array $payload): void;

    /**
     * Return the serialised payload for the currently active tenant.
     *
     * The payload is stored alongside queued jobs so that the correct tenant
     * context can be restored when the job is processed.
     *
     * @return null|array<string, mixed> The tenant context payload, or `null` if no tenant is active.
     */
    public function tenantPayload(): ?array;

    /**
     * Restore the landlord context from a serialised landlord payload.
     *
     * @param array<string, mixed> $payload A landlord payload previously produced by `LandlordContext::payload()`.
     */
    public function fromLandlordPayload(array $payload): void;

    /**
     * Return the serialised payload for the currently active landlord.
     *
     * The payload is stored alongside queued jobs so that the correct landlord
     * context can be restored when the job is processed.
     *
     * @return null|array<string, mixed> The landlord context payload, or `null` if no landlord is active.
     */
    public function landlordPayload(): ?array;
}
