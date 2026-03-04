<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Tasks;

use Cline\Tenancy\Contracts\TaskInterface;
use Cline\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

use function config;
use function is_string;
use function sprintf;

/**
 * Scopes the Laravel cache to the active tenant by rewriting the cache prefix.
 *
 * When a tenant context becomes active, this task builds a prefix in the form
 * `{basePrefix}{delimiter}{tenantId}` — for example `tenant:42` — and writes it
 * to `cache.prefix`. The cache driver is then flushed via `Cache::forgetDriver()`
 * so that subsequent cache operations use the new prefix and are fully isolated
 * to the active tenant.
 *
 * The original `cache.prefix` value is captured on the first `makeCurrent` call
 * and restored by `forgetCurrent`, returning the cache to its pre-tenancy state
 * after the context is popped.
 *
 * Configuration keys (all optional — defaults shown):
 * - `tenancy.cache.prefix`    — base prefix string, defaults to `"tenant"`
 * - `tenancy.cache.delimiter` — separator between prefix and tenant ID, defaults to `":"`
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class PrefixCacheTask implements TaskInterface
{
    /**
     * The `cache.prefix` value that was active before the first tenant context was pushed.
     *
     * Captured once on the first `makeCurrent` call and used by `forgetCurrent`
     * to restore the original prefix after the tenant context is cleared.
     */
    private ?string $originalPrefix = null;

    /**
     * Apply a tenant-scoped cache prefix and reset the cache driver.
     *
     * Builds a prefix from `tenancy.cache.prefix`, `tenancy.cache.delimiter`, and
     * the active tenant's ID. Saves the original prefix on the first invocation,
     * then sets the new prefix on `cache.prefix` and calls `Cache::forgetDriver()`
     * to force the cache manager to re-instantiate the driver with the updated config.
     *
     * @param TenantContext $tenantContext The tenant context that is becoming active.
     */
    public function makeCurrent(TenantContext $tenantContext): void
    {
        $configuredPrefix = config('cache.prefix');

        if ($this->originalPrefix === null && is_string($configuredPrefix)) {
            $this->originalPrefix = $configuredPrefix;
        }

        $basePrefix = config('tenancy.cache.prefix', 'tenant');
        $delimiter = config('tenancy.cache.delimiter', ':');

        if (!is_string($basePrefix) || $basePrefix === '') {
            $basePrefix = 'tenant';
        }

        if (!is_string($delimiter) || $delimiter === '') {
            $delimiter = ':';
        }

        $prefix = sprintf('%s%s%s', $basePrefix, $delimiter, (string) $tenantContext->id());

        config()->set('cache.prefix', $prefix);
        Cache::forgetDriver();
    }

    /**
     * Restore the original cache prefix and reset the cache driver.
     *
     * Writes the prefix that was captured before the first tenant context was pushed
     * back to `cache.prefix`, then calls `Cache::forgetDriver()` so the cache manager
     * picks up the restored configuration on the next access.
     *
     * @param TenantContext $tenantContext The tenant context that is being deactivated.
     */
    public function forgetCurrent(TenantContext $tenantContext): void
    {
        if ($this->originalPrefix !== null) {
            config()->set('cache.prefix', $this->originalPrefix);
        }

        Cache::forgetDriver();
    }
}
