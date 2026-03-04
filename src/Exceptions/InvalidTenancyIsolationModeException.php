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
 * Thrown when `tenancy.isolation` or `tenancy.landlord.isolation` contains an unrecognised value.
 *
 * Valid values are the string-backed cases of {@see \Cline\Tenancy\Enums\IsolationMode}:
 * `shared-database`, `separate-schema`, and `separate-database`.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class InvalidTenancyIsolationModeException extends InvalidTenancyConfiguration
{
    /**
     * Create an exception for an unrecognised isolation mode value.
     *
     * @param string $value The invalid value read from the configuration.
     */
    public static function forValue(string $value): self
    {
        return new self(sprintf('Configured tenancy isolation mode [%s] is invalid.', $value));
    }
}
