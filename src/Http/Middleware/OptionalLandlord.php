<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Http\Middleware;

use Cline\Tenancy\Contracts\TenancyInterface;
use Cline\Tenancy\Exceptions\UnexpectedMiddlewareResponseException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use function throw_unless;

/**
 * Optionally resolve and activate a landlord context for the duration of the request.
 *
 * Attempts to identify a landlord from the incoming request using the configured
 * resolver. Unlike `RequireLandlord`, this middleware does not abort when no
 * landlord is found — the request proceeds regardless.
 *
 * The landlord and tenant contexts are always cleared in a `finally` block after
 * the request completes, ensuring contexts do not leak between requests even if
 * the pipeline throws.
 *
 * Use this middleware on routes that may optionally belong to a landlord but
 * should also be accessible without one.
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class OptionalLandlord
{
    /**
     * @param TenancyInterface $tenancy The tenancy service used to resolve and clear the landlord context.
     */
    public function __construct(
        private TenancyInterface $tenancy,
    ) {}

    /**
     * Attempt to resolve a landlord context and pass the request to the next handler.
     *
     * @param Request $request The incoming HTTP request.
     * @param Closure $next    The next middleware handler.
     *
     * @throws UnexpectedMiddlewareResponseException When the pipeline returns a non-Symfony response.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->tenancy->resolveLandlord($request);

        try {
            $response = $next($request);

            throw_unless($response instanceof Response, UnexpectedMiddlewareResponseException::expectedSymfonyResponse());

            return $response;
        } finally {
            $this->tenancy->forgetCurrentLandlord();
            $this->tenancy->forgetCurrentTenant();
        }
    }
}
