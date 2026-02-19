<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Contracts;

use Cline\Tenancy\LandlordContext;

/**
 * Side-effect contract executed when the active landlord context changes.
 *
 * Tasks are registered with the tenancy layer and called in sequence whenever
 * the landlord context is pushed onto or popped off the stack. Common
 * implementations switch the active database connection, configure
 * cache prefixes, or map landlord-specific config values.
 *
 * Implementations must be idempotent within a single request and must restore
 * any state they modify inside `forgetCurrent` to avoid leaking configuration
 * between landlord context switches.
 *
 * @author Brian Faust <brian@cline.sh>
 * @see \Cline\Tenancy\Tasks\SwitchLandlordDatabaseTask
 * @see \Cline\Tenancy\Tasks\MapLandlordConfigTask
 */
interface LandlordTaskInterface
{
    /**
     * Apply side effects required when a landlord context becomes active.
     *
     * Called by the tenancy layer immediately after a new `LandlordContext` is
     * pushed onto the stack, for example when `runAsLandlord()` is invoked or
     * when middleware resolves the landlord for an incoming request.
     *
     * @param LandlordContext $landlordContext The landlord context that is now active.
     */
    public function makeCurrent(LandlordContext $landlordContext): void;

    /**
     * Reverse the side effects applied in `makeCurrent`.
     *
     * Called by the tenancy layer immediately before the `LandlordContext` is
     * popped off the stack. Implementations should fully restore the state that
     * existed before `makeCurrent` ran to ensure clean context boundaries.
     *
     * @param LandlordContext $landlordContext The landlord context that is being deactivated.
     */
    public function forgetCurrent(LandlordContext $landlordContext): void;
}
