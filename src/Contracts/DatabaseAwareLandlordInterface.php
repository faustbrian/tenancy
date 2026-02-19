<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Contracts;

/**
 * Contract for landlords that carry their own database connection configuration.
 *
 * Implement this interface on a landlord model when each landlord uses a
 * dedicated database or connection that differs from the application default.
 * The `SwitchLandlordDatabaseTask` reads the returned configuration to
 * dynamically register and switch to the landlord-specific connection at
 * runtime.
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface DatabaseAwareLandlordInterface extends LandlordInterface
{
    /**
     * Return the database connection configuration for this landlord.
     *
     * The array shape mirrors a standard Laravel `config/database.php`
     * connection entry (e.g. `driver`, `host`, `database`, `username`,
     * `password`). Return `null` when the landlord should use the application's
     * default connection instead of a dedicated one.
     *
     * @return null|array<string, mixed> Connection parameters, or `null` to use the default connection.
     */
    public function databaseConfig(): ?array;
}
