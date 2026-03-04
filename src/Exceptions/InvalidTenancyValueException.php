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
 * Thrown when a tenancy configuration key holds an unrecognised or malformed value.
 *
 * Used as a catch-all for configuration keys that do not have a more specific
 * exception subclass. Raised during service-provider boot when the package
 * validates the `config/tenancy.php` values.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class InvalidTenancyValueException extends InvalidTenancyConfiguration
{
    /**
     * Create an exception for an invalid value at the given configuration key.
     *
     * @param string $key   The `config/tenancy.php` key that holds the invalid value.
     * @param string $value The invalid value that was configured.
     */
    public static function forKey(string $key, string $value): self
    {
        return new self(sprintf('Configured tenancy %s [%s] is invalid.', $key, $value));
    }
}
