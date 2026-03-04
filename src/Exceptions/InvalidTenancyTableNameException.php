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
 * Thrown when an entry under `tenancy.table_names` contains an invalid table name.
 *
 * The service provider validates each key in the `table_names` configuration array
 * (`landlords`, `tenants`, `tenant_domains`, `landlord_domains`) during boot. A value
 * is considered invalid when it is empty or otherwise fails the package's naming rules.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class InvalidTenancyTableNameException extends InvalidTenancyConfiguration
{
    /**
     * Create an exception for an invalid table name under a specific configuration key.
     *
     * @param string $key   The dot-notation key within `table_names` (e.g. `tenants`, `landlords`).
     * @param string $value The invalid value read from the configuration.
     */
    public static function forKey(string $key, string $value): self
    {
        return new self(sprintf('Configured tenancy table_names.%s [%s] is invalid.', $key, $value));
    }
}
