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
use function is_int;
use function is_numeric;
use function is_string;

/**
 * Resolves the active tenant from a URL path segment.
 *
 * Reads the 1-based segment position from `tenancy.resolver.path_segment`
 * (default: `1`) and treats that segment's value as the tenant identifier. Falls
 * back to segment 1 when the configured value is not a valid integer or numeric
 * string. Returns null when the segment is absent or empty, or when no matching
 * tenant exists in the repository.
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class PathTenantResolver implements TenantResolverInterface
{
    /**
     * Create a new path-based tenant resolver.
     *
     * @param TenantRepositoryInterface $tenants Repository used to look up tenants by identifier.
     */
    public function __construct(
        private TenantRepositoryInterface $tenants,
    ) {}

    /**
     * Resolve the tenant from the specified URL path segment.
     *
     * The segment position is read from the `tenancy.resolver.path_segment` config
     * key and defaults to `1`. Invalid config values (non-integer, non-numeric) are
     * silently reset to `1`. Returns null when the segment is missing or empty, or
     * when no tenant matches the extracted identifier.
     *
     * @param  Request              $request The incoming HTTP request.
     * @return null|TenantInterface The resolved tenant, or null if resolution fails.
     */
    public function resolve(Request $request): ?TenantInterface
    {
        $segment = config('tenancy.resolver.path_segment', 1);

        if (!is_int($segment) && (!is_string($segment) || !is_numeric($segment))) {
            $segment = 1;
        }

        $identifier = $request->segment((int) $segment);

        if (!is_string($identifier) || $identifier === '') {
            return null;
        }

        return $this->tenants->findByIdentifier($identifier);
    }
}
