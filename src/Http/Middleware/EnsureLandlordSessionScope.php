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
 * Prevent session fixation attacks by binding the session to a single landlord.
 *
 * On each request, this middleware compares the currently active landlord's ID
 * against the landlord ID stored in the session. If the session contains an ID
 * from a different landlord, the request is aborted — and optionally the session
 * is invalidated — to stop a session from being reused across landlord boundaries.
 *
 * Behaviour:
 * - No active landlord: clears the landlord scope key from the session.
 * - First request with a landlord: writes the landlord ID into the session.
 * - Subsequent requests: asserts the stored ID matches the active landlord.
 * - Mismatch detected: aborts with the configured HTTP status (default 403)
 *   and, when `tenancy.session.invalidate_on_mismatch` is `true`, invalidates
 *   the entire session before aborting.
 *
 * Configuration keys:
 * - `tenancy.session.landlord_scope_key`   — session key used to store the landlord ID (default: `tenancy.landlord_id`)
 * - `tenancy.session.abort_status`         — HTTP status code on mismatch (default: `403`)
 * - `tenancy.session.invalidate_on_mismatch` — whether to invalidate the session on mismatch (default: `true`)
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class EnsureLandlordSessionScope
{
    /**
     * @param TenancyInterface $tenancy The tenancy service used to read the current landlord ID.
     */
    public function __construct(
        private TenancyInterface $tenancy,
    ) {}

    /**
     * Enforce the landlord session scope on the incoming request.
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
            $currentLandlordId = $this->tenancy->landlordId();
            $storedLandlordId = $request->session()->get($key);

            if ($currentLandlordId === null) {
                $request->session()->forget($key);
            } elseif ($storedLandlordId === null) {
                $request->session()->put($key, (string) $currentLandlordId);
            } elseif (!is_int($storedLandlordId) && !is_string($storedLandlordId)) {
                abort($this->abortStatus(), 'Landlord session scope mismatch.');
            } elseif ((string) $storedLandlordId !== (string) $currentLandlordId) {
                if ($this->invalidateOnMismatch()) {
                    $request->session()->invalidate();
                }

                abort($this->abortStatus(), 'Landlord session scope mismatch.');
            }
        }

        $response = $next($request);
        throw_unless($response instanceof Response, UnexpectedMiddlewareResponseException::expectedSymfonyResponse());

        return $response;
    }

    /**
     * Return the session key used to store the landlord scope ID.
     */
    private function scopeKey(): string
    {
        $key = config('tenancy.session.landlord_scope_key', 'tenancy.landlord_id');

        return is_string($key) && $key !== '' ? $key : 'tenancy.landlord_id';
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
