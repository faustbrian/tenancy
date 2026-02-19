<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Resolvers;

use Cline\Tenancy\Contracts\LandlordInterface;
use Cline\Tenancy\Contracts\LandlordRepositoryInterface;
use Cline\Tenancy\Contracts\LandlordResolverInterface;
use Illuminate\Http\Request;

use function config;
use function is_array;
use function is_int;
use function is_string;

/**
 * Resolves the active landlord from the HTTP session.
 *
 * Reads the session key from `tenancy.landlord.resolver.session_key` (default:
 * `landlord`) and inspects the stored value using a series of type checks to
 * support multiple storage shapes:
 *
 * - `LandlordInterface` instance — returned directly.
 * - Scalar `int` or `string` — used as an identifier to query the repository.
 * - Associative array with an `id` key (int or string) — looked up by identifier.
 * - Associative array with a `slug` key (string) — looked up by slug.
 *
 * Returns null when the session is unavailable, the config key is invalid, the
 * session value does not match any supported shape, or no landlord is found.
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class SessionLandlordResolver implements LandlordResolverInterface
{
    /**
     * Create a new session-based landlord resolver.
     *
     * @param LandlordRepositoryInterface $landlords Repository used to look up landlords by identifier or slug.
     */
    public function __construct(
        private LandlordRepositoryInterface $landlords,
    ) {}

    /**
     * Resolve the landlord from the current session.
     *
     * The session key is read from `tenancy.landlord.resolver.session_key` and
     * defaults to `landlord`. The method accepts several stored value shapes: a
     * `LandlordInterface` object, a scalar identifier, or an array containing an
     * `id` or `slug` key. Returns null when the request has no session, the
     * configured key is invalid, the value shape is unrecognised, or no matching
     * landlord is found.
     *
     * @param  Request                $request The incoming HTTP request.
     * @return null|LandlordInterface The resolved landlord, or null if resolution fails.
     */
    public function resolve(Request $request): ?LandlordInterface
    {
        if (!$request->hasSession()) {
            return null;
        }

        $key = config('tenancy.landlord.resolver.session_key', 'landlord');

        if (!is_string($key) || $key === '') {
            return null;
        }

        $value = $request->session()->get($key);

        if ($value instanceof LandlordInterface) {
            return $value;
        }

        if (is_int($value) || is_string($value)) {
            return $this->landlords->findByIdentifier($value);
        }

        if (!is_array($value)) {
            return null;
        }

        if (isset($value['id']) && (is_int($value['id']) || is_string($value['id']))) {
            return $this->landlords->findByIdentifier($value['id']);
        }

        if (isset($value['slug']) && is_string($value['slug'])) {
            return $this->landlords->findBySlug($value['slug']);
        }

        return null;
    }
}
