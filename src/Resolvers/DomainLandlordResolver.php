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
use Cline\Tenancy\Support\DomainNormalizer;
use Illuminate\Http\Request;

use function config;
use function is_array;
use function is_string;
use function str_ends_with;

/**
 * Resolves the current landlord from the request's hostname.
 *
 * Normalises the incoming hostname via {@see DomainNormalizer} and delegates to
 * the landlord repository's domain-lookup strategy. Before querying the repository,
 * it checks whether the host matches any of the configured central (non-tenant)
 * domains and returns null for those — preventing the tenancy layer from activating
 * on admin or marketing routes that should always run in the central application
 * context.
 *
 * Central domains are configured under `tenancy.landlord.resolver.central_domains`
 * as an array of fully-qualified hostnames or apex domains. A host is considered
 * central when it matches a configured domain exactly or is a subdomain of it
 * (e.g. `app.example.com` is central when `example.com` is listed).
 *
 * Returns null when the hostname cannot be normalised, the host is a central domain,
 * or no landlord is associated with the domain.
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class DomainLandlordResolver implements LandlordResolverInterface
{
    /**
     * Create a new domain landlord resolver.
     *
     * @param LandlordRepositoryInterface $landlords The repository used to look up a landlord
     *                                               by the normalised request hostname.
     */
    public function __construct(
        private LandlordRepositoryInterface $landlords,
    ) {}

    /**
     * Resolve the current landlord from the request hostname.
     *
     * @param  Request                $request The current HTTP request.
     * @return null|LandlordInterface The resolved landlord, or null if the host is central or unmatched.
     */
    public function resolve(Request $request): ?LandlordInterface
    {
        $host = DomainNormalizer::normalize($request->getHost());

        if ($host === null) {
            return null;
        }

        if ($this->isCentralDomain($host)) {
            return null;
        }

        return $this->landlords->findByDomain($host);
    }

    /**
     * Determine whether the given host is a configured central domain.
     *
     * A host is considered central when it equals a configured central domain or
     * is a subdomain of one. Both the candidate host and each configured domain
     * are normalised before comparison to prevent case or trailing-dot mismatches.
     *
     * @param  string $host The normalised hostname extracted from the request.
     * @return bool   True when the host should not trigger landlord resolution.
     */
    private function isCentralDomain(string $host): bool
    {
        $configuredCentralDomains = config('tenancy.landlord.resolver.central_domains', []);

        if (!is_array($configuredCentralDomains)) {
            return false;
        }

        foreach ($configuredCentralDomains as $configuredCentralDomain) {
            if (!is_string($configuredCentralDomain)) {
                continue;
            }

            $normalizedCentralDomain = DomainNormalizer::normalize($configuredCentralDomain);

            if ($normalizedCentralDomain === null) {
                continue;
            }

            if ($host === $normalizedCentralDomain || str_ends_with($host, '.'.$normalizedCentralDomain)) {
                return true;
            }
        }

        return false;
    }
}
