<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Http\Middleware;

use Cline\Tenancy\Contracts\TenantImpersonationManagerInterface;
use Cline\Tenancy\Exceptions\UnexpectedMiddlewareResponseException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use function is_array;
use function is_string;
use function throw_unless;

/**
 * Activate a tenant impersonation session from a single-use query parameter token.
 *
 * When the configured query parameter is present on the incoming request, this
 * middleware passes the token to `TenantImpersonationManagerInterface::applyToken()`,
 * which consumes the token, activates the associated tenant context, and returns
 * the payload. If a session is available, the authenticated user identity from the
 * payload (`user_id` and `guard`) is stored under `tenancy.impersonation` so that
 * downstream code can log the user in as the impersonated principal.
 *
 * Tokens are single-use and short-lived. If the token is missing, invalid, or
 * already consumed, this middleware is a no-op and the request proceeds normally.
 *
 * The query parameter name is configured via `tenancy.impersonation.query_parameter`
 * (default: `tenant_impersonation`).
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class TenantImpersonation
{
    /**
     * @param TenantImpersonationManagerInterface $impersonation The manager used to consume tokens and activate tenant contexts.
     */
    public function __construct(
        private TenantImpersonationManagerInterface $impersonation,
    ) {}

    /**
     * Consume any impersonation token present in the request and pass it to the next handler.
     *
     * @param Request $request The incoming HTTP request.
     * @param Closure $next    The next middleware handler.
     *
     * @throws UnexpectedMiddlewareResponseException When the pipeline returns a non-Symfony response.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $parameter = $this->impersonation->queryParameter();
        $token = $request->query($parameter);

        if (is_string($token) && $token !== '') {
            $payload = $this->impersonation->applyToken($token);

            if (is_array($payload) && $request->hasSession()) {
                $request->session()->put('tenancy.impersonation', [
                    'user_id' => $payload['user_id'] ?? null,
                    'guard' => $payload['guard'] ?? null,
                ]);
            }
        }

        $response = $next($request);
        throw_unless($response instanceof Response, UnexpectedMiddlewareResponseException::expectedSymfonyResponse());

        return $response;
    }
}
