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
 * Thrown when a configured resolver class does not implement the required resolver contract.
 *
 * Both `tenancy.resolver` and `tenancy.landlord.resolver` are validated during boot.
 * Each entry must be a class that implements {@see \Cline\Tenancy\Contracts\TenantResolverInterface}
 * (for tenant resolvers) or the equivalent landlord resolver interface, depending on context.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class InvalidTenancyResolverException extends InvalidTenancyConfiguration
{
    /**
     * Create an exception for a class that does not implement the resolver contract.
     *
     * @param string $resolverClass The fully-qualified class name that failed validation.
     */
    public static function forClass(string $resolverClass): self
    {
        return new self(sprintf('Configured tenancy resolver [%s] must implement TenantResolverInterface.', $resolverClass));
    }
}
