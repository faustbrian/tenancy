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

use function abort;
use function config;
use function is_int;
use function is_numeric;
use function is_string;
use function throw_unless;

/**
 * Prevent session fixation attacks by binding the session to a single tenant.
 *
 * On each request, this middleware compares the currently active tenant's ID
 * against the tenant ID stored in the session. If the session contains an ID
 * from a different tenant, the request is aborted — and optionally the session
 * is invalidated — to stop a session from being reused across tenant boundaries.
 *
 * Behaviour:
 * - No active tenant: clears the tenant scope key from the session.
 * - First request with a tenant: writes the tenant ID into the session.
 * - Subsequent requests: asserts the stored ID matches the active tenant.
 * - Mismatch detected: aborts with the configured HTTP status (default 403)
 *   and, when `tenancy.session.invalidate_on_mismatch` is `true`, invalidates
 *   the entire session before aborting.
 *
 * Configuration keys:
 * - `tenancy.session.tenant_scope_key`       — session key used to store the tenant ID (default: `tenancy.tenant_id`)
 * - `tenancy.session.abort_status`           — HTTP status code on mismatch (default: `403`)
 * - `tenancy.session.invalidate_on_mismatch` — whether to invalidate the session on mismatch (default: `true`)
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class EnsureTenantSessionScope
{
    /**
     * @param TenancyInterface $tenancy The tenancy service used to read the current tenant ID.
     */
    public function __construct(
        private TenancyInterface $tenancy,
    ) {}

    /**
     * Enforce the tenant session scope on the incoming request.
     *
     * @param Request $request The incoming HTTP request.
     * @param Closure $next    The next middleware handler.
     *
     * @throws UnexpectedMiddlewareResponseException When the pipeline returns a non-Symfony response.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession()) {
            $key = $this->scopeKey();
            $currentTenantId = $this->tenancy->tenantId();
            $storedTenantId = $request->session()->get($key);

            if ($currentTenantId === null) {
                $request->session()->forget($key);
            } elseif ($storedTenantId === null) {
                $request->session()->put($key, (string) $currentTenantId);
            } elseif (!is_int($storedTenantId) && !is_string($storedTenantId)) {
                abort($this->abortStatus(), 'Tenant session scope mismatch.');
            } elseif ((string) $storedTenantId !== (string) $currentTenantId) {
                if ($this->invalidateOnMismatch()) {
                    $request->session()->invalidate();
                }

                abort($this->abortStatus(), 'Tenant session scope mismatch.');
            }
        }

        $response = $next($request);
        throw_unless($response instanceof Response, UnexpectedMiddlewareResponseException::expectedSymfonyResponse());

        return $response;
    }

    /**
     * Return the session key used to store the tenant scope ID.
     */
    private function scopeKey(): string
    {
        $key = config('tenancy.session.tenant_scope_key', 'tenancy.tenant_id');

        return is_string($key) && $key !== '' ? $key : 'tenancy.tenant_id';
    }

    /**
     * Return the HTTP status code to use when aborting on a scope mismatch.
     */
    private function abortStatus(): int
    {
        $status = config('tenancy.session.abort_status', 403);

        if (!is_int($status) && (!is_string($status) || !is_numeric($status))) {
            return 403;
        }

        return (int) $status;
    }

    /**
     * Determine whether the session should be invalidated when a mismatch is detected.
     */
    private function invalidateOnMismatch(): bool
    {
        return (bool) config('tenancy.session.invalidate_on_mismatch', true);
    }
}
