<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Resolvers;

use Cline\Tenancy\Contracts\TenantInterface;
use Cline\Tenancy\Contracts\TenantRepositoryInterface;
use Cline\Tenancy\Contracts\TenantResolverInterface;
use Illuminate\Http\Request;

use function config;
use function is_array;
use function is_int;
use function is_string;

/**
 * Resolves the active tenant from the HTTP session.
 *
 * Reads the session key from `tenancy.resolver.session_key` (default: `tenant`)
 * and inspects the stored value using a series of type checks to support multiple
 * storage shapes:
 *
 * - `TenantInterface` instance — returned directly.
 * - Scalar `int` or `string` — used as an identifier to query the repository.
 * - Associative array with an `id` key (int or string) — looked up by identifier.
 * - Associative array with a `slug` key (string) — looked up by slug.
 *
 * Returns null when the session is unavailable, the config key is invalid, the
 * session value does not match any supported shape, or no tenant is found.
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class SessionTenantResolver implements TenantResolverInterface
{
    /**
     * Create a new session-based tenant resolver.
     *
     * @param TenantRepositoryInterface $tenants Repository used to look up tenants by identifier or slug.
     */
    public function __construct(
        private TenantRepositoryInterface $tenants,
    ) {}

    /**
     * Resolve the tenant from the current session.
     *
     * The session key is read from `tenancy.resolver.session_key` and defaults to
     * `tenant`. The method accepts several stored value shapes: a `TenantInterface`
     * object, a scalar identifier, or an array containing an `id` or `slug` key.
     * Returns null when the request has no session, the configured key is invalid,
     * the value shape is unrecognised, or no matching tenant is found.
     *
     * @param  Request              $request The incoming HTTP request.
     * @return null|TenantInterface The resolved tenant, or null if resolution fails.
     */
    public function resolve(Request $request): ?TenantInterface
    {
        if (!$request->hasSession()) {
            return null;
        }

        $key = config('tenancy.resolver.session_key', 'tenant');

        if (!is_string($key) || $key === '') {
            return null;
        }

        $value = $request->session()->get($key);

        if ($value instanceof TenantInterface) {
            return $value;
        }

        if (is_int($value) || is_string($value)) {
            return $this->tenants->findByIdentifier($value);
        }

        if (!is_array($value)) {
            return null;
        }

        if (isset($value['id']) && (is_int($value['id']) || is_string($value['id']))) {
            return $this->tenants->findByIdentifier($value['id']);
        }

        if (isset($value['slug']) && is_string($value['slug'])) {
            return $this->tenants->findBySlug($value['slug']);
        }

        return null;
    }
}
