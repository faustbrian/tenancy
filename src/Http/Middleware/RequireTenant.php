<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Http\Middleware;

use Cline\Tenancy\Contracts\TenancyInterface;
use Cline\Tenancy\Exceptions\TenantNotResolved;
use Cline\Tenancy\Exceptions\UnexpectedMiddlewareResponseException;
use Cline\Tenancy\TenantContext;
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
 * Require a resolvable tenant context before allowing the request to proceed.
 *
 * Attempts to identify a tenant from the incoming request using the configured
 * resolver. If no tenant can be resolved, the request is immediately aborted with
 * the configured HTTP status code (default 404).
 *
 * The tenant and landlord contexts are always cleared in a `finally` block after
 * the request completes, ensuring contexts do not leak between requests even if
 * the pipeline throws.
 *
 * Use this middleware on routes that must always execute within a specific tenant
 * context, such as tenant-specific dashboards or scoped API endpoints.
 *
 * Configuration keys:
 * - `tenancy.http.abort_status` — HTTP status code when no tenant is resolved (default: `404`)
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class RequireTenant
{
    /**
     * @param TenancyInterface $tenancy The tenancy service used to resolve and clear the tenant context.
     */
    public function __construct(
        private TenancyInterface $tenancy,
    ) {}

    /**
     * Resolve the tenant context and pass the request to the next handler.
     *
     * Aborts with the configured status code if no tenant can be resolved
     * from the request.
     *
     * @param Request $request The incoming HTTP request.
     * @param Closure $next    The next middleware handler.
     *
     * @throws UnexpectedMiddlewareResponseException When the pipeline returns a non-Symfony response.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $context = $this->tenancy->resolveTenant($request);

        if (!$context instanceof TenantContext) {
            $status = config('tenancy.http.abort_status', 404);

            if (!is_int($status) && (!is_string($status) || !is_numeric($status))) {
                $status = 404;
            }

            abort((int) $status, TenantNotResolved::forHost($request->getHost())->getMessage());
        }

        try {
            $response = $next($request);

            throw_unless($response instanceof Response, UnexpectedMiddlewareResponseException::expectedSymfonyResponse());

            return $response;
        } finally {
            $this->tenancy->forgetCurrentTenant();
            $this->tenancy->forgetCurrentLandlord();
        }
    }
}
