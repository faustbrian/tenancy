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
use function is_string;

/**
 * Resolves the current landlord from a request header.
 *
 * Reads a configurable HTTP header (default: `X-LandlordInterface`, overridden via
 * `tenancy.landlord.resolver.header`) and treats its value as an opaque landlord
 * identifier — either a primary key or a slug. The identifier is passed directly to
 * {@see LandlordRepositoryInterface::findByIdentifier()}.
 *
 * This resolver is suited for internal service-to-service communication and API
 * clients that know the landlord ahead of time and can supply it explicitly on
 * each request. It should not be exposed to untrusted callers without an
 * authentication layer that validates the header value.
 *
 * Returns null when the configured header name is absent from config, the header
 * is missing from the request, or no landlord matches the supplied identifier.
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class HeaderLandlordResolver implements LandlordResolverInterface
{
    /**
     * Create a new header landlord resolver.
     *
     * @param LandlordRepositoryInterface $landlords The repository used to look up a landlord
     *                                               by the identifier value found in the header.
     */
    public function __construct(
        private LandlordRepositoryInterface $landlords,
    ) {}

    /**
     * Resolve the current landlord from the configured request header.
     *
     * @param  Request                $request The current HTTP request.
     * @return null|LandlordInterface The resolved landlord, or null if the header is absent or unmatched.
     */
    public function resolve(Request $request): ?LandlordInterface
    {
        $header = config('tenancy.landlord.resolver.header', 'X-LandlordInterface');

        if (!is_string($header) || $header === '') {
            return null;
        }

        $identifier = $request->headers->get($header);

        if (!is_string($identifier) || $identifier === '') {
            return null;
        }

        return $this->landlords->findByIdentifier($identifier);
    }
}
