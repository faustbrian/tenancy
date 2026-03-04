<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Resolvers;

use Cline\Tenancy\Contracts\TenantInterface;
use Cline\Tenancy\Contracts\TenantResolverInterface;
use Illuminate\Http\Request;

/**
 * Resolves the current tenant by delegating to an ordered list of resolvers.
 *
 * Iterates through each resolver in the supplied sequence and returns the first
 * non-null result. This allows multiple resolution strategies — such as domain,
 * header, and authenticated-user — to be composed without coupling the tenancy
 * middleware to any specific strategy.
 *
 * Returns null only when every resolver in the chain returns null, indicating
 * that the request cannot be associated with a tenant.
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class ChainTenantResolver implements TenantResolverInterface
{
    /**
     * Create a new chain tenant resolver.
     *
     * @param iterable<TenantResolverInterface> $resolvers An ordered sequence of resolvers to try.
     *                                                     Resolution stops at the first non-null result.
     */
    public function __construct(
        private iterable $resolvers,
    ) {}

    /**
     * Resolve the current tenant by trying each resolver in order.
     *
     * @param  Request              $request The current HTTP request.
     * @return null|TenantInterface The first successfully resolved tenant, or null if all resolvers fail.
     */
    public function resolve(Request $request): ?TenantInterface
    {
        foreach ($this->resolvers as $resolver) {
            $tenant = $resolver->resolve($request);

            if ($tenant instanceof TenantInterface) {
                return $tenant;
            }
        }

        return null;
    }
}
