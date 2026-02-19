<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Contracts;

/**
 * Contract for landlords that are identified by one or more domain names.
 *
 * Implement this interface on a landlord model when the tenancy system should
 * resolve the active landlord from the incoming request's hostname. Domain
 * resolvers such as `DomainLandlordResolver` and `SubdomainLandlordResolver`
 * depend on this interface to match request hostnames against registered
 * landlord domains.
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface DomainAwareLandlordInterface extends LandlordInterface
{
    /**
     * Return the list of domain names associated with this landlord.
     *
     * Each entry should be a fully-qualified hostname (e.g. `"landlord.example.com"`)
     * or a bare subdomain label used for subdomain-based resolution. The list
     * may contain multiple values when a landlord is reachable under several
     * domains or aliases.
     *
     * @return array<int, string> Ordered list of domain names for this landlord.
     */
    public function domains(): array;
}
