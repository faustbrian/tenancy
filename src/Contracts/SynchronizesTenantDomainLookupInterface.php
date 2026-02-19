<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Contracts;

/**
 * Contract for keeping a fast tenant domain lookup store in sync.
 *
 * Domain-based resolvers typically query a cache or secondary data store to
 * avoid a full database lookup on every request. Implementations of this
 * interface are responsible for writing to and invalidating that store whenever
 * a tenant's domain associations change — for example, in model observers or
 * event listeners triggered after tenant creation or update.
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface SynchronizesTenantDomainLookupInterface
{
    /**
     * Write or refresh the domain lookup entries for the given tenant.
     *
     * Called after a tenant is created or its domain associations are updated.
     * Implementations should upsert all domains returned by the tenant so that
     * subsequent resolver lookups reflect the latest state.
     *
     * @param TenantInterface $tenant The tenant whose domain entries should be synchronised.
     */
    public function syncDomainLookup(TenantInterface $tenant): void;

    /**
     * Remove all domain lookup entries associated with the given tenant id.
     *
     * Called before or after a tenant is deleted so that stale entries do not
     * cause incorrect resolutions. The id is accepted directly rather than a
     * full tenant instance because the record may no longer exist in the
     * primary store at the time of purging.
     *
     * @param int|string $tenantId The primary key of the tenant whose entries should be removed.
     */
    public function purgeDomainLookup(int|string $tenantId): void;
}
