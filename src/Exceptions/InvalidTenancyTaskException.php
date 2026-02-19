<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Exceptions;

use function sprintf;

/**
 * Thrown when a class listed under `tenancy.tasks` does not implement the task contract.
 *
 * Tasks are bootstrapping units that run when the active tenant or landlord context
 * changes (e.g. switching the database connection or prefixing the cache). Every
 * class listed in `tenancy.tasks` must implement {@see \Cline\Tenancy\Contracts\TaskInterface}
 * and every class in `tenancy.landlord.tasks` must implement
 * {@see \Cline\Tenancy\Contracts\LandlordTaskInterface}.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class InvalidTenancyTaskException extends InvalidTenancyConfiguration
{
    /**
     * Create an exception for a class that does not implement the task contract.
     *
     * @param string $taskClass The fully-qualified class name that failed validation.
     */
    public static function forClass(string $taskClass): self
    {
        return new self(sprintf('Configured tenancy task [%s] must implement TaskInterface.', $taskClass));
    }
}
