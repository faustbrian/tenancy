<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Contracts;

/**
 * Contract for executing scheduled work across all tenants or landlords.
 *
 * Intended for use inside Laravel's scheduler (`app/Console/Kernel.php`) to
 * run a task in every tenant or landlord context without the caller having to
 * manage context switching or error handling. The concrete implementation
 * iterates over each entity, activates its context via the tenancy layer, and
 * invokes the provided callback.
 *
 * Error handling behaviour (fail-fast vs. collect-and-rethrow) is controlled
 * by the `tenancy.scheduler.fail_fast` configuration value.
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface TenancySchedulerInterface
{
    /**
     * Execute a callback once for each tenant in the system.
     *
     * The callback receives the `TenantInterface` instance for the currently
     * active tenant. The tenant context is activated before the callback runs
     * and cleaned up afterwards, even if the callback throws. When
     * `fail_fast` is enabled, the first exception aborts the loop; otherwise
     * all tenants are processed and the first captured exception is re-thrown
     * after the loop completes.
     *
     * @param callable(TenantInterface): void $callback The work to perform inside each tenant context.
     */
    public function eachTenant(callable $callback): void;

    /**
     * Execute a callback once for each landlord in the system.
     *
     * The callback receives the `LandlordInterface` instance for the currently
     * active landlord. The landlord context is activated before the callback
     * runs and cleaned up afterwards, even if the callback throws. When
     * `fail_fast` is enabled, the first exception aborts the loop; otherwise
     * all landlords are processed and the first captured exception is re-thrown
     * after the loop completes.
     *
     * @param callable(LandlordInterface): void $callback The work to perform inside each landlord context.
     */
    public function eachLandlord(callable $callback): void;
}
