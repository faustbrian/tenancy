<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Facades;

use Cline\Tenancy\Contracts\LandlordInterface;
use Cline\Tenancy\Contracts\LandlordResolverInterface;
use Cline\Tenancy\Contracts\TenancyInterface;
use Cline\Tenancy\Contracts\TenantInterface;
use Cline\Tenancy\Contracts\TenantResolverInterface;
use Cline\Tenancy\Enums\IsolationMode;
use Cline\Tenancy\LandlordContext;
use Cline\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

/**
 * Facade providing static access to the `TenancyInterface` implementation.
 *
 * All methods proxy to the `TenancyInterface` instance bound in the service
 * container. Refer to `TenancyInterface` for full method documentation,
 * including parameter constraints, return values, and thrown exceptions.
 *
 * @method static iterable<LandlordInterface> allLandlords()                                                                                         Return all landlords in the system.
 * @method static iterable<TenantInterface>   allTenants()                                                                                           Return all tenants in the system.
 * @method static LandlordContext|null        currentLandlord()                                                                                      Return the currently active landlord context, or `null` if none is set.
 * @method static TenantContext|null          currentTenant()                                                                                        Return the currently active tenant context, or `null` if none is set.
 * @method static void                        forgetCurrentLandlord()                                                                                Pop the current landlord context off the stack and reverse its tasks.
 * @method static void                        forgetCurrentTenant()                                                                                  Pop the current tenant context off the stack and reverse its tasks.
 * @method static void                        fromLandlordPayload(array<string, mixed> $payload)                                                     Restore the landlord context from a serialised landlord payload.
 * @method static void                        fromTenancyPayload(array<string, mixed> $payload)                                                      Restore both the tenant and landlord context from a combined tenancy payload.
 * @method static void                        fromTenantPayload(array<string, mixed> $payload)                                                       Restore the tenant context from a serialised tenant payload.
 * @method static LandlordContext|null        landlord(LandlordInterface|LandlordContext|int|string|null $landlord)                                  Resolve the given value to a `LandlordContext` without making it active.
 * @method static LandlordContext|null        landlordBySlug(string $slug)                                                                           Resolve a landlord context by its URL-safe slug.
 * @method static string|null                 landlordConnection()                                                                                   Return the configured database connection name for landlord isolation.
 * @method static int|string|null             landlordId()                                                                                           Return the primary key of the currently active landlord.
 * @method static IsolationMode               landlordIsolation()                                                                                    Return the isolation mode configured for landlords.
 * @method static array<string, mixed>|null   landlordPayload()                                                                                      Return the serialised payload for the currently active landlord.
 * @method static LandlordResolverInterface   landlordResolver()                                                                                     Return the resolver responsible for identifying landlords from HTTP requests.
 * @method static string                      landlordScopedQueue(string $queue, LandlordInterface|LandlordContext|int|string|null $landlord = null) Return a queue name scoped to the given landlord.
 * @method static LandlordContext|null        resolveLandlord(Request $request)                                                                      Attempt to resolve and activate the landlord for the incoming request.
 * @method static TenantContext|null          resolveTenant(Request $request)                                                                        Attempt to resolve and activate the tenant for the incoming request.
 * @method static mixed                       runAsLandlord(LandlordInterface|LandlordContext|int|string $landlord, callable $callback)              Execute a callback within the given landlord's context.
 * @method static mixed                       runAsSystem(callable $callback)                                                                        Execute a callback outside of any tenant or landlord context.
 * @method static mixed                       runAsTenant(TenantInterface|TenantContext|int|string $tenant, callable $callback)                      Execute a callback within the given tenant's context.
 * @method static array<string, mixed>|null   tenancyPayload()                                                                                       Return the serialised payload representing both the current tenant and landlord.
 * @method static TenantContext|null          tenant(TenantInterface|TenantContext|int|string|null $tenant)                                          Resolve the given value to a `TenantContext` without making it active.
 * @method static TenantContext|null          tenantBySlug(string $slug)                                                                             Resolve a tenant context by its URL-safe slug.
 * @method static string|null                 tenantConnection()                                                                                     Return the configured database connection name for tenant isolation.
 * @method static int|string|null             tenantId()                                                                                             Return the primary key of the currently active tenant.
 * @method static IsolationMode               tenantIsolation()                                                                                      Return the isolation mode configured for tenants.
 * @method static array<string, mixed>|null   tenantPayload()                                                                                        Return the serialised payload for the currently active tenant.
 * @method static TenantResolverInterface     tenantResolver()                                                                                       Return the resolver responsible for identifying tenants from HTTP requests.
 * @method static string                      tenantScopedQueue(string $queue, TenantInterface|TenantContext|int|string|null $tenant = null)         Return a queue name scoped to the given tenant.
 *
 * @author Brian Faust <brian@cline.sh>
 * @see TenancyInterface
 */
final class Tenancy extends Facade
{
    /**
     * Return the service container binding key for this facade.
     */
    protected static function getFacadeAccessor(): string
    {
        return TenancyInterface::class;
    }
}
