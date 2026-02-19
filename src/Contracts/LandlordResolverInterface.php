<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Contracts;

use Illuminate\Http\Request;

/**
 * Strategy contract for resolving the active landlord from an HTTP request.
 *
 * Each resolver encapsulates a single resolution strategy — domain, subdomain,
 * header, path segment, authenticated user, or session. The tenancy layer
 * delegates to the configured resolver (or a chain of resolvers via
 * `ChainLandlordResolver`) when middleware needs to establish the landlord
 * context at the start of a request lifecycle.
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface LandlordResolverInterface
{
    /**
     * Attempt to resolve the active landlord from the incoming request.
     *
     * Returns `null` when the resolver cannot determine a landlord from the
     * available request data. Middleware implementations treat a `null` return
     * as "no landlord found" and may either continue to the next resolver in a
     * chain or abort the request depending on the configured behaviour.
     *
     * @param  Request                $request The current HTTP request.
     * @return null|LandlordInterface The resolved landlord, or `null` if unresolvable.
     */
    public function resolve(Request $request): ?LandlordInterface;
}
