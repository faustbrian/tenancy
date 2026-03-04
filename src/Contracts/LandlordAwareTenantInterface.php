<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Contracts;

/**
 * Contract for tenants that belong to a specific landlord.
 *
 * Implement this interface on a tenant model when the system operates in a
 * hierarchical multi-tenancy mode where tenants are scoped under a parent
 * landlord. The tenancy layer uses the returned id to enforce landlord
 * isolation and to validate that the active tenant is consistent with the
 * active landlord context.
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface LandlordAwareTenantInterface
{
    /**
     * Return the id of the landlord this tenant belongs to.
     *
     * Returns `null` when the tenant is not associated with a landlord, which
     * is valid in flat (non-hierarchical) tenancy configurations. When present,
     * the value should match the primary key type used by the landlord model.
     *
     * @return null|int|string The landlord's primary key, or `null` if unassigned.
     */
    public function landlordId(): int|string|null;
}
