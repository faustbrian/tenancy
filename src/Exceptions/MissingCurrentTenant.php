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
 * Thrown when a tenant-scoped Eloquent query executes without an active tenant context.
 *
 * Raised by the `BelongsToTenant` concern's global scope when a query against
 * a tenant-aware model is executed and no tenant has been activated via
 * `Tenancy::runAsTenant()` or the tenant resolver middleware.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class MissingCurrentTenant extends RuntimeException implements TenancyExceptionInterface
{
    /**
     * Create an exception for a tenant-scoped model queried without an active tenant.
     *
     * @param string $model The fully-qualified class name of the model being queried.
     */
    public static function forModel(string $model): self
    {
        return new self(sprintf('No current tenant is set while querying model [%s].', $model));
    }
}
