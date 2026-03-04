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
 * Thrown when `tenancy.primary_key_type` contains an unrecognised value.
 *
 * The configured value must match a case of `Cline\VariableKeys\Enums\PrimaryKeyType`
 * (e.g. `id`, `uuid`). The service provider validates this during boot and throws
 * this exception before any database migrations or model bindings are registered.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class InvalidTenancyPrimaryKeyTypeException extends InvalidTenancyConfiguration
{
    /**
     * Create an exception for an unrecognised primary key type value.
     *
     * @param string $value The invalid value read from the configuration.
     */
    public static function forValue(string $value): self
    {
        return new self(sprintf('Configured tenancy primary_key_type [%s] is invalid.', $value));
    }
}
