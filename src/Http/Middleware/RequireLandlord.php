<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Http\Middleware;

use Cline\Tenancy\Contracts\TenancyInterface;
use Cline\Tenancy\Exceptions\LandlordNotResolved;
use Cline\Tenancy\Exceptions\UnexpectedMiddlewareResponseException;
use Cline\Tenancy\LandlordContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use function abort;
use function config;
use function is_int;
use function is_numeric;
use function is_string;
use function throw_unless;

/**
 * Require a resolvable landlord context before allowing the request to proceed.
 *
 * Attempts to identify a landlord from the incoming request using the configured
 * resolver. If no landlord can be resolved, the request is immediately aborted with
 * the configured HTTP status code (default 404).
 *
 * The landlord and tenant contexts are always cleared in a `finally` block after
 * the request completes, ensuring contexts do not leak between requests even if
 * the pipeline throws.
 *
 * Use this middleware on routes that must always execute within a specific landlord
 * context, such as landlord-specific admin panels or API endpoints.
 *
 * Configuration keys:
 * - `tenancy.http.abort_status` — HTTP status code when no landlord is resolved (default: `404`)
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class RequireLandlord
{
    /**
     * @param TenancyInterface $tenancy The tenancy service used to resolve and clear the landlord context.
     */
    public function __construct(
        private TenancyInterface $tenancy,
    ) {}

    /**
     * Resolve the landlord context and pass the request to the next handler.
     *
     * Aborts with the configured status code if no landlord can be resolved
     * from the request.
     *
     * @param Request $request The incoming HTTP request.
     * @param Closure $next    The next middleware handler.
     *
     * @throws UnexpectedMiddlewareResponseException When the pipeline returns a non-Symfony response.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $context = $this->tenancy->resolveLandlord($request);

        if (!$context instanceof LandlordContext) {
            $status = config('tenancy.http.abort_status', 404);

            if (!is_int($status) && (!is_string($status) || !is_numeric($status))) {
                $status = 404;
            }

            abort((int) $status, LandlordNotResolved::forHost($request->getHost())->getMessage());
        }

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
