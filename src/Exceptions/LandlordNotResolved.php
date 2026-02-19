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
 * Thrown when no landlord can be identified for the incoming request's hostname.
 *
 * Raised by the landlord resolver middleware when the configured
 * `LandlordResolverInterface` cannot match the request host to a known
 * landlord record. Catching `TenancyExceptionInterface` will also catch
 * this exception.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class LandlordNotResolved extends RuntimeException implements TenancyExceptionInterface
{
    /**
     * Create an exception for a hostname that could not be matched to a landlord.
     *
     * @param string $host The request hostname for which resolution failed.
     */
    public static function forHost(string $host): self
    {
        return new self(sprintf('Landlord not resolved for host [%s].', $host));
    }
}
