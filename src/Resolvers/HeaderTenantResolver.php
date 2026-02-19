<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Resolvers;

use Cline\Tenancy\Contracts\TenantInterface;
use Cline\Tenancy\Contracts\TenantRepositoryInterface;
use Cline\Tenancy\Contracts\TenantResolverInterface;
use Illuminate\Http\Request;

use function config;
use function is_string;

/**
 * Resolves the active tenant from an HTTP request header.
 *
 * Reads the header name from `tenancy.resolver.header` (default: `X-TenantInterface`)
 * and looks up the tenant by the header's value via the tenant repository. Returns
 * null when the config key is invalid, the header is absent or empty, or no matching
 * tenant exists.
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class HeaderTenantResolver implements TenantResolverInterface
{
    /**
     * Create a new header-based tenant resolver.
     *
     * @param TenantRepositoryInterface $tenants Repository used to look up tenants by identifier.
     */
    public function __construct(
        private TenantRepositoryInterface $tenants,
    ) {}

    /**
     * Resolve the tenant from the incoming request's HTTP header.
     *
     * The header name is read from the `tenancy.resolver.header` config key and
     * defaults to `X-TenantInterface`. Returns null when the configured header name
     * is not a non-empty string, when the header is missing from the request, or
     * when no tenant matches the header value.
     *
     * @param  Request              $request The incoming HTTP request.
     * @return null|TenantInterface The resolved tenant, or null if resolution fails.
     */
    public function resolve(Request $request): ?TenantInterface
    {
        $header = config('tenancy.resolver.header', 'X-TenantInterface');

        if (!is_string($header) || $header === '') {
            return null;
        }

        $identifier = $request->headers->get($header);

        if (!is_string($identifier) || $identifier === '') {
            return null;
        }

        return $this->tenants->findByIdentifier($identifier);
    }
}
