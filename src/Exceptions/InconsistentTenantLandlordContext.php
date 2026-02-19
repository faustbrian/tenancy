<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Exceptions;

use RuntimeException;

use function sprintf;

/**
 * Thrown when the landlord being activated does not match the landlord that owns the active tenant.
 *
 * When `tenancy.context.enforce_coherence` is enabled (the default), the tenancy
 * service asserts that every landlord context switch is compatible with the current
 * tenant context. This exception is raised when the tenant is associated with a
 * different landlord than the one being pushed onto the landlord stack, preventing
 * cross-landlord data leakage at runtime.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class InconsistentTenantLandlordContext extends RuntimeException implements TenancyExceptionInterface
{
    /**
     * Create an exception for a landlord mismatch on a given tenant context.
     *
     * @param int|string $tenantIdentifier           The identifier of the tenant whose landlord association was checked.
     * @param int|string $expectedLandlordIdentifier The landlord identifier the tenant expects based on its own data.
     * @param int|string $actualLandlordIdentifier   The landlord identifier that was actually being activated.
     */
    public static function forIdentifiers(int|string $tenantIdentifier, int|string $expectedLandlordIdentifier, int|string $actualLandlordIdentifier): self
    {
        return new self(sprintf(
            'Tenant context [%s] expects landlord [%s], got [%s].',
            (string) $tenantIdentifier,
            (string) $expectedLandlordIdentifier,
            (string) $actualLandlordIdentifier,
        ));
    }
}
