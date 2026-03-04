<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Exceptions;

use RuntimeException;

use function is_int;
use function is_string;
use function sprintf;

/**
 * Thrown when a landlord identifier cannot be resolved to a `LandlordContext`.
 *
 * Raised by `TenancyInterface::landlord()` and `TenancyInterface::runAsLandlord()`
 * when the provided identifier — an id, slug, model instance, or existing context —
 * cannot be matched to a persisted landlord record. When the identifier is an
 * int or string, it is included in the message to aid debugging.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class UnresolvedLandlordContext extends RuntimeException implements TenancyExceptionInterface
{
    /**
     * Create an exception for an identifier that could not be resolved to a landlord context.
     *
     * Produces a message containing the identifier value when it is a scalar
     * (int or string); falls back to a generic message for all other types.
     *
     * @param mixed $identifier The value that failed to resolve (id, slug, model, or other).
     */
    public static function forIdentifier(mixed $identifier): self
    {
        if (is_int($identifier) || is_string($identifier)) {
            return new self(sprintf('Unable to resolve landlord context for identifier [%s].', (string) $identifier));
        }

        return new self('Unable to resolve landlord context.');
    }
}
