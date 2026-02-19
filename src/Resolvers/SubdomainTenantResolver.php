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
use Cline\Tenancy\Support\DomainNormalizer;
use Illuminate\Http\Request;

use function array_filter;
use function array_values;
use function config;
use function count;
use function explode;
use function is_array;
use function is_string;
use function reset;
use function str_ends_with;

/**
 * Resolves the active tenant from the request's subdomain.
 *
 * Normalizes the incoming host, skips any domain listed in
 * `tenancy.resolver.central_domains`, and extracts the first label of a
 * fully-qualified hostname (e.g., `acme` from `acme.example.com`) as the tenant
 * slug. Requires at least three dot-separated parts to distinguish a subdomain
 * from a bare domain or IP address.
 *
 * Returns null when the host cannot be normalized, the host matches a central
 * domain, the hostname has fewer than three parts, or no tenant matches the
 * extracted slug.
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class SubdomainTenantResolver implements TenantResolverInterface
{
    /**
     * Create a new subdomain-based tenant resolver.
     *
     * @param TenantRepositoryInterface $tenants Repository used to look up tenants by slug.
     */
    public function __construct(
        private TenantRepositoryInterface $tenants,
    ) {}

    /**
     * Resolve the tenant from the request host's leading subdomain label.
     *
     * The host is normalized via {@see DomainNormalizer::normalize()} before
     * processing. Hosts that match or are sub-hosts of a configured central domain
     * are excluded. Returns null when the host is invalid, is a central domain,
     * has fewer than three dot-separated parts, or no tenant is found for the
     * extracted slug.
     *
     * @param  Request              $request The incoming HTTP request.
     * @return null|TenantInterface The resolved tenant, or null if resolution fails.
     */
    public function resolve(Request $request): ?TenantInterface
    {
        $host = DomainNormalizer::normalize($request->getHost());

        if ($host === null) {
            return null;
        }

        if ($this->isCentralDomain($host)) {
            return null;
        }

        $parts = array_values(array_filter(explode('.', $host)));

        if (count($parts) < 3) {
            return null;
        }

        $slug = reset($parts);

        return $this->tenants->findBySlug($slug);
    }

    /**
     * Determine whether the given host is a configured central domain.
     *
     * Reads the list of central domains from `tenancy.resolver.central_domains`.
     * A host is considered central when it exactly matches a normalized central domain
     * or when it is a subdomain of one (i.e., the host ends with `.<centralDomain>`).
     *
     * @param  string $host The normalized hostname to test.
     * @return bool   True when the host is a central domain or one of its subdomains.
     */
    private function isCentralDomain(string $host): bool
    {
        $configuredCentralDomains = config('tenancy.resolver.central_domains', []);

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
