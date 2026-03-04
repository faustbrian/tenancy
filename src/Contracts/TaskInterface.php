<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Contracts;

use Cline\Tenancy\TenantContext;

/**
 * Side-effect contract executed when the active tenant context changes.
 *
 * Tasks are registered with the tenancy layer and called in sequence whenever
 * the tenant context is pushed onto or popped off the stack. Common
 * implementations switch the active database connection, configure cache
 * prefixes, or map tenant-specific config values.
 *
 * Implementations must be idempotent within a single request and must restore
 * any state they modify inside `forgetCurrent` to avoid leaking configuration
 * between tenant context switches.
 *
 * @author Brian Faust <brian@cline.sh>
 * @see \Cline\Tenancy\Tasks\SwitchTenantDatabaseTask
 * @see \Cline\Tenancy\Tasks\MapTenantConfigTask
 * @see \Cline\Tenancy\Tasks\PrefixCacheTask
 */
interface TaskInterface
{
    /**
     * Apply side effects required when a tenant context becomes active.
     *
     * Called by the tenancy layer immediately after a new `TenantContext` is
     * pushed onto the stack, for example when `runAsTenant()` is invoked or
     * when middleware resolves the tenant for an incoming request.
     *
     * @param TenantContext $tenantContext The tenant context that is now active.
     */
    public function makeCurrent(TenantContext $tenantContext): void;

    /**
     * Reverse the side effects applied in `makeCurrent`.
     *
     * Called by the tenancy layer immediately before the `TenantContext` is
     * popped off the stack. Implementations should fully restore the state that
     * existed before `makeCurrent` ran to ensure clean context boundaries.
     *
     * @param TenantContext $tenantContext The tenant context that is being deactivated.
     */
    public function forgetCurrent(TenantContext $tenantContext): void;
}
