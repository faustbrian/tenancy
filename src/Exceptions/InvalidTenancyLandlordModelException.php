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
 * Thrown when `tenancy.landlord_model` refers to a class that is not a valid landlord model.
 *
 * The configured class must extend `Illuminate\Database\Eloquent\Model` and implement
 * {@see \Cline\Tenancy\Contracts\LandlordInterface} to be accepted by the service provider.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class InvalidTenancyLandlordModelException extends InvalidTenancyConfiguration
{
    /**
     * Create an exception for a class that does not satisfy the landlord model contract.
     *
     * @param string $modelClass The fully-qualified class name that failed validation.
     */
    public static function forClass(string $modelClass): self
    {
        return new self(sprintf('Configured tenancy landlord_model [%s] must extend Eloquent Model and implement Landlord contract.', $modelClass));
    }
}
