<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Resolvers;

use Cline\Tenancy\Contracts\LandlordInterface;
use Cline\Tenancy\Contracts\LandlordResolverInterface;
use Illuminate\Http\Request;

/**
 * Resolves the current landlord by delegating to an ordered list of resolvers.
 *
 * Iterates through each resolver in the supplied sequence and returns the first
 * non-null result. This allows multiple resolution strategies — such as domain,
 * header, and authenticated-user — to be composed without coupling the tenancy
 * middleware to any specific strategy.
 *
 * Returns null only when every resolver in the chain returns null, indicating
 * that the request cannot be associated with a landlord.
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class ChainLandlordResolver implements LandlordResolverInterface
{
    /**
     * Create a new chain landlord resolver.
     *
     * @param iterable<LandlordResolverInterface> $resolvers An ordered sequence of resolvers to try.
     *                                                       Resolution stops at the first non-null result.
     */
    public function __construct(
        private iterable $resolvers,
    ) {}

    /**
     * Resolve the current landlord by trying each resolver in order.
     *
     * @param  Request                $request The current HTTP request.
     * @return null|LandlordInterface The first successfully resolved landlord, or null if all resolvers fail.
     */
    public function resolve(Request $request): ?LandlordInterface
    {
        foreach ($this->resolvers as $resolver) {
            $landlord = $resolver->resolve($request);

            if ($landlord instanceof LandlordInterface) {
                return $landlord;
            }
        }

        return null;
    }
}
