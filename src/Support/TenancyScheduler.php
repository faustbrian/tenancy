<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Support;

use Cline\Tenancy\Contracts\LandlordRepositoryInterface;
use Cline\Tenancy\Contracts\TenancyInterface;
use Cline\Tenancy\Contracts\TenancySchedulerInterface;
use Cline\Tenancy\Contracts\TenantRepositoryInterface;
use Throwable;

use function config;
use function throw_if;

/**
 * Runs scheduled callbacks in the context of every tenant or landlord.
 *
 * Iterates over all tenants or landlords and executes the provided callback
 * within the appropriate tenancy context using {@see TenancyInterface::runAsTenant()}
 * or {@see TenancyInterface::runAsLandlord()}. Failure handling is controlled by
 * the `tenancy.scheduler.fail_fast` config key:
 *
 * - When `fail_fast` is `true` (default), the first exception immediately
 *   propagates, aborting any remaining iterations.
 * - When `fail_fast` is `false`, all tenants/landlords are processed and the
 *   first encountered exception is re-thrown after the loop completes.
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class TenancyScheduler implements TenancySchedulerInterface
{
    /**
     * Create a new tenancy scheduler.
     *
     * @param LandlordRepositoryInterface $landlords Repository providing all landlord instances to iterate over.
     * @param TenantRepositoryInterface   $tenants   Repository providing all tenant instances to iterate over.
     * @param TenancyInterface            $tenancy   Tenancy service used to switch context for each iteration.
     */
    public function __construct(
        private LandlordRepositoryInterface $landlords,
        private TenantRepositoryInterface $tenants,
        private TenancyInterface $tenancy,
    ) {}

    /**
     * Execute a callback once for every tenant, each time in that tenant's context.
     *
     * When `tenancy.scheduler.fail_fast` is `true`, the first exception aborts
     * the loop immediately. When `false`, all tenants are processed and the first
     * failure is re-thrown after all iterations complete.
     *
     * @param  callable  $callback Invoked with the current `TenantInterface` instance as its sole argument.
     * @throws Throwable When one or more tenant callbacks throw and fail-fast behaviour applies.
     */
    public function eachTenant(callable $callback): void
    {
        $firstFailure = null;

        foreach ($this->tenants->all() as $tenant) {
            try {
                $this->tenancy->runAsTenant($tenant, static fn () => $callback($tenant));
            } catch (Throwable $throwable) {
                throw_if($this->failFast(), $throwable);

                $firstFailure ??= $throwable;
            }
        }

        if (!$firstFailure instanceof Throwable) {
            return;
        }

        throw_if(true, $firstFailure);
    }

    /**
     * Execute a callback once for every landlord, each time in that landlord's context.
     *
     * When `tenancy.scheduler.fail_fast` is `true`, the first exception aborts
     * the loop immediately. When `false`, all landlords are processed and the first
     * failure is re-thrown after all iterations complete.
     *
     * @param  callable  $callback Invoked with the current `LandlordInterface` instance as its sole argument.
     * @throws Throwable When one or more landlord callbacks throw and fail-fast behaviour applies.
     */
    public function eachLandlord(callable $callback): void
    {
        $firstFailure = null;

        foreach ($this->landlords->all() as $landlord) {
            try {
                $this->tenancy->runAsLandlord($landlord, static fn () => $callback($landlord));
            } catch (Throwable $throwable) {
                throw_if($this->failFast(), $throwable);

                $firstFailure ??= $throwable;
            }
        }

        if (!$firstFailure instanceof Throwable) {
            return;
        }

        throw_if(true, $firstFailure);
    }

    /**
     * Determine whether the scheduler should abort on the first failure.
     *
     * Reads the `tenancy.scheduler.fail_fast` config key and defaults to `true`.
     *
     * @return bool True when the first callback exception should immediately propagate.
     */
    private function failFast(): bool
    {
        return (bool) config('tenancy.scheduler.fail_fast', true);
    }
}
