<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy;

use Cline\Tenancy\Contracts\DomainAwareLandlordInterface;
use Cline\Tenancy\Contracts\LandlordInterface;
use Cline\Tenancy\Contracts\TenancyInterface;
use Cline\Tenancy\Contracts\TenancySchedulerInterface;
use Cline\Tenancy\Contracts\TenantInterface;

use const PHP_URL_SCHEME;

use function config;
use function explode;
use function function_exists;
use function is_array;
use function is_string;
use function mb_ltrim;
use function mb_rtrim;
use function parse_url;
use function resolve;
use function route;
use function sprintf;
use function str_contains;
use function url;

if (!function_exists(__NAMESPACE__.'\\tenancy')) {
    /**
     * Resolve the tenancy service from the container.
     */
    function tenancy(): TenancyInterface
    {
        return resolve(TenancyInterface::class);
    }
}

if (!function_exists(__NAMESPACE__.'\\tenancy_scheduler')) {
    /**
     * Resolve the tenancy scheduler service from the container.
     */
    function tenancy_scheduler(): TenancySchedulerInterface
    {
        return resolve(TenancySchedulerInterface::class);
    }
}

if (!function_exists(__NAMESPACE__.'\\landlord')) {
    /**
     * Resolve a landlord context for the given value, or return the currently active landlord.
     *
     * When `$landlord` is provided, delegates to `TenancyInterface::landlord()` to resolve
     * it to a `LandlordContext`. When no argument is given, returns the context currently
     * active on the stack via `TenancyInterface::currentLandlord()`.
     *
     * @param  null|int|LandlordContext|LandlordInterface|string $landlord The landlord to resolve, or `null` to use the current context.
     * @return null|LandlordContext                              The resolved context, or `null` if no context is active.
     */
    function landlord(LandlordInterface|LandlordContext|int|string|null $landlord = null): ?LandlordContext
    {
        return tenancy()->landlord($landlord) ?? tenancy()->currentLandlord();
    }
}

if (!function_exists(__NAMESPACE__.'\\landlord_action')) {
    /**
     * Execute a callback within the appropriate landlord context.
     *
     * When `$landlord` is provided, runs the callback inside that landlord's context via
     * `runAsLandlord()`. When omitted, uses the currently active landlord if one is set,
     * or falls back to system context via `runAsSystem()` when no landlord is active.
     *
     * @param  callable                                          $callback The work to perform.
     * @param  null|int|LandlordContext|LandlordInterface|string $landlord The landlord context to use, or `null` to auto-detect.
     * @return mixed                                             The value returned by `$callback`.
     */
    function landlord_action(
        callable $callback,
        LandlordInterface|LandlordContext|int|string|null $landlord = null,
    ): mixed {
        if ($landlord !== null) {
            return tenancy()->runAsLandlord($landlord, $callback);
        }

        $currentLandlord = tenancy()->currentLandlord();

        if ($currentLandlord instanceof LandlordContext) {
            return tenancy()->runAsLandlord($currentLandlord, $callback);
        }

        return tenancy()->runAsSystem($callback);
    }
}

if (!function_exists(__NAMESPACE__.'\\tenant')) {
    /**
     * Resolve a tenant context for the given value, or return the currently active tenant.
     *
     * When `$tenant` is provided, delegates to `TenancyInterface::tenant()` to resolve
     * it to a `TenantContext`. When no argument is given, returns the context currently
     * active on the stack via `TenancyInterface::currentTenant()`.
     *
     * @param  null|int|string|TenantContext|TenantInterface $tenant The tenant to resolve, or `null` to use the current context.
     * @return null|TenantContext                            The resolved context, or `null` if no context is active.
     */
    function tenant(TenantInterface|TenantContext|int|string|null $tenant = null): ?TenantContext
    {
        return tenancy()->tenant($tenant) ?? tenancy()->currentTenant();
    }
}

if (!function_exists(__NAMESPACE__.'\\tenant_action')) {
    /**
     * Execute a callback within the appropriate tenant context.
     *
     * When `$tenant` is provided, runs the callback inside that tenant's context via
     * `runAsTenant()`. When omitted, uses the currently active tenant if one is set,
     * or falls back to system context via `runAsSystem()` when no tenant is active.
     *
     * @param  callable                                      $callback The work to perform.
     * @param  null|int|string|TenantContext|TenantInterface $tenant   The tenant context to use, or `null` to auto-detect.
     * @return mixed                                         The value returned by `$callback`.
     */
    function tenant_action(
        callable $callback,
        TenantInterface|TenantContext|int|string|null $tenant = null,
    ): mixed {
        if ($tenant !== null) {
            return tenancy()->runAsTenant($tenant, $callback);
        }

        $currentTenant = tenancy()->currentTenant();

        if ($currentTenant instanceof TenantContext) {
            return tenancy()->runAsTenant($currentTenant, $callback);
        }

        return tenancy()->runAsSystem($callback);
    }
}

if (!function_exists(__NAMESPACE__.'\\landlord_context')) {
    /**
     * Alias for `landlord()`. Resolve a landlord context for the given value, or return the active landlord.
     *
     * @param  null|int|LandlordContext|LandlordInterface|string $landlord The landlord to resolve, or `null` to use the current context.
     * @return null|LandlordContext                              The resolved context, or `null` if no context is active.
     */
    function landlord_context(LandlordInterface|LandlordContext|int|string|null $landlord = null): ?LandlordContext
    {
        return landlord($landlord);
    }
}

if (!function_exists(__NAMESPACE__.'\\tenant_context')) {
    /**
     * Alias for `tenant()`. Resolve a tenant context for the given value, or return the active tenant.
     *
     * @param  null|int|string|TenantContext|TenantInterface $tenant The tenant to resolve, or `null` to use the current context.
     * @return null|TenantContext                            The resolved context, or `null` if no context is active.
     */
    function tenant_context(TenantInterface|TenantContext|int|string|null $tenant = null): ?TenantContext
    {
        return tenant($tenant);
    }
}

if (!function_exists(__NAMESPACE__.'\\tenant_route')) {
    /**
     * Generate a URL for a named route with the tenant slug injected automatically.
     *
     * The tenant route parameter name is read from `tenancy.routing.tenant_parameter`
     * (defaults to `"tenant"`). When a tenant context is active, its slug is merged
     * into `$parameters` before calling `route()`.
     *
     * @param  string                                        $name       The named route to generate a URL for.
     * @param  array<string, mixed>                          $parameters Additional route parameters to merge.
     * @param  bool                                          $absolute   Whether to generate an absolute URL.
     * @param  null|int|string|TenantContext|TenantInterface $tenant     The tenant to use, or `null` to use the current context.
     * @return string                                        The generated URL.
     */
    function tenant_route(
        string $name,
        array $parameters = [],
        bool $absolute = true,
        TenantInterface|TenantContext|int|string|null $tenant = null,
    ): string {
        $context = tenant($tenant);

        if ($context instanceof TenantContext) {
            $parameterKey = config('tenancy.routing.tenant_parameter', 'tenant');

            if (!is_string($parameterKey) || $parameterKey === '') {
                $parameterKey = 'tenant';
            }

            $parameters[$parameterKey] = $context->slug();
        }

        return route($name, $parameters, $absolute);
    }
}

if (!function_exists(__NAMESPACE__.'\\landlord_route')) {
    /**
     * Generate a URL for a named route with the landlord slug injected automatically.
     *
     * The landlord route parameter name is read from `tenancy.routing.landlord_parameter`
     * (defaults to `"landlord"`). When a landlord context is active, its slug is merged
     * into `$parameters` before calling `route()`.
     *
     * @param  string                                            $name       The named route to generate a URL for.
     * @param  array<string, mixed>                              $parameters Additional route parameters to merge.
     * @param  bool                                              $absolute   Whether to generate an absolute URL.
     * @param  null|int|LandlordContext|LandlordInterface|string $landlord   The landlord to use, or `null` to use the current context.
     * @return string                                            The generated URL.
     */
    function landlord_route(
        string $name,
        array $parameters = [],
        bool $absolute = true,
        LandlordInterface|LandlordContext|int|string|null $landlord = null,
    ): string {
        $context = landlord($landlord);

        if ($context instanceof LandlordContext) {
            $parameterKey = config('tenancy.routing.landlord_parameter', 'landlord');

            if (!is_string($parameterKey) || $parameterKey === '') {
                $parameterKey = 'landlord';
            }

            $parameters[$parameterKey] = $context->slug();
        }

        return route($name, $parameters, $absolute);
    }
}

if (!function_exists(__NAMESPACE__.'\\tenant_url')) {
    /**
     * Build an absolute URL for the given path scoped to a tenant's primary domain.
     *
     * Retrieves the first domain from the tenant's domain list and constructs the URL
     * using the scheme derived from `app.url`. Falls back to `url($path)` when no
     * tenant context is active or the tenant has no configured domains.
     *
     * @param  null|int|string|TenantContext|TenantInterface $tenant The tenant whose domain should be used, or `null` for the current tenant.
     * @param  string                                        $path   The path to append to the domain. Defaults to `/`.
     * @return string                                        The fully-qualified URL.
     */
    function tenant_url(
        TenantInterface|TenantContext|int|string|null $tenant = null,
        string $path = '/',
    ): string {
        $context = tenant($tenant);
        $cleanPath = '/'.mb_ltrim($path, '/');

        if (!$context instanceof TenantContext) {
            return url($cleanPath);
        }

        $domains = $context->tenant->domains();

        if ($domains === []) {
            return url($cleanPath);
        }

        $host = $domains[0];
        $appUrl = config('app.url', 'http://localhost');

        if (!is_string($appUrl) || $appUrl === '') {
            $appUrl = 'http://localhost';
        }

        $scheme = parse_url($appUrl, PHP_URL_SCHEME);

        if (!is_string($scheme) || $scheme === '') {
            $scheme = 'https';
        }

        if (str_contains($host, '/')) {
            $parts = explode('/', $host);
            $host = $parts[0];
        }

        return sprintf('%s://%s%s', mb_rtrim($scheme, ':'), $host, $cleanPath);
    }
}

if (!function_exists(__NAMESPACE__.'\\landlord_url')) {
    /**
     * Build an absolute URL for the given path scoped to a landlord's primary domain.
     *
     * First checks for domains via `DomainAwareLandlordInterface::domains()`, then falls
     * back to the `domains` key in the landlord's context payload. Constructs the URL
     * using the scheme derived from `app.url`. Falls back to `url($path)` when no
     * landlord context is active or the landlord has no configured domains.
     *
     * @param  null|int|LandlordContext|LandlordInterface|string $landlord The landlord whose domain should be used, or `null` for the current landlord.
     * @param  string                                            $path     The path to append to the domain. Defaults to `/`.
     * @return string                                            The fully-qualified URL.
     */
    function landlord_url(
        LandlordInterface|LandlordContext|int|string|null $landlord = null,
        string $path = '/',
    ): string {
        $context = landlord($landlord);
        $cleanPath = '/'.mb_ltrim($path, '/');

        if (!$context instanceof LandlordContext) {
            return url($cleanPath);
        }

        $domains = null;

        if ($context->landlord instanceof DomainAwareLandlordInterface) {
            $domains = $context->landlord->domains();
        }

        if (!is_array($domains)) {
            $payload = $context->landlord->getContextPayload();
            $domains = $payload['domains'] ?? null;
        }

        if (!is_array($domains) || $domains === []) {
            return url($cleanPath);
        }

        $host = $domains[0] ?? null;

        if (!is_string($host) || $host === '') {
            return url($cleanPath);
        }

        $appUrl = config('app.url', 'http://localhost');

        if (!is_string($appUrl) || $appUrl === '') {
            $appUrl = 'http://localhost';
        }

        $scheme = parse_url($appUrl, PHP_URL_SCHEME);

        if (!is_string($scheme) || $scheme === '') {
            $scheme = 'https';
        }

        if (str_contains($host, '/')) {
            $parts = explode('/', $host);
            $host = $parts[0];
        }

        return sprintf('%s://%s%s', mb_rtrim($scheme, ':'), $host, $cleanPath);
    }
}
