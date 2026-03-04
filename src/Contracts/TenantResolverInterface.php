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
 * Defines the contract for resolving the active tenant from an HTTP request.
 *
 * Each implementation encapsulates a single resolution strategy — for example,
 * inspecting the request domain, a path segment, a header, or the authenticated
 * user. Multiple resolvers may be composed via a chain resolver so that
 * different strategies are tried in order until one succeeds.
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface TenantResolverInterface
{
    /**
     * Attempt to resolve the active tenant from the incoming request.
     *
     * Returns null when the resolver cannot identify a tenant, allowing
     * the caller or a chain resolver to fall through to the next strategy.
     *
     * @param  Request              $request The current HTTP request being handled.
     * @return null|TenantInterface The resolved tenant, or null if unresolvable.
     */
    public function resolve(Request $request): ?TenantInterface;
}
