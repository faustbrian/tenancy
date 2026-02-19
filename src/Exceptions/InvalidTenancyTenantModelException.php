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
 * Thrown when the configured `tenant_model` class is not a valid tenant model.
 *
 * The configured class must extend `\Illuminate\Database\Eloquent\Model` and
 * implement `\Cline\Tenancy\Contracts\TenantInterface`. This exception is raised
 * during service-provider boot, before the application handles any requests.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class InvalidTenancyTenantModelException extends InvalidTenancyConfiguration
{
    /**
     * Create an exception for a class that fails the tenant model requirements.
     *
     * @param string $modelClass The fully-qualified class name that failed validation.
     */
    public static function forClass(string $modelClass): self
    {
        return new self(sprintf('Configured tenancy tenant_model [%s] must extend Eloquent Model and implement Tenant contract.', $modelClass));
    }
}
