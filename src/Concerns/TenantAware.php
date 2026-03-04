<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Concerns;

use Cline\Tenancy\Contracts\TenancyInterface;

use function resolve;

/**
 * Captures the current tenancy context so it can be restored in asynchronous workloads.
 *
 * Add this trait to queued jobs or other async objects that must run inside
 * the same tenant context as the code that dispatched them. Call
 * `withTenantContext()` before dispatching; the captured payload is serialized
 * with the job and later restored by the tenancy system when the job is
 * executed on a worker.
 *
 * ```php
 * class SendWelcomeEmail implements ShouldQueue
 * {
 *     use TenantAware;
 * }
 *
 * dispatch((new SendWelcomeEmail($user))->withTenantContext());
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
trait TenantAware
{
    /**
     * The serialized tenancy payload captured at dispatch time.
     *
     * Holds the combined tenant and landlord context returned by
     * `TenancyInterface::tenancyPayload()`. The value is `null` until
     * `withTenantContext()` is called. When the job is executed, the tenancy
     * system reads this property to restore the correct tenant context on the
     * worker process.
     *
     * @var null|array<string, mixed>
     */
    public ?array $tenancy = null;

    /**
     * Capture the active tenancy context onto this object.
     *
     * Reads the current tenant and landlord payload from `TenancyInterface`
     * and stores it in the `$tenancy` property so it survives serialization.
     * Returns the same instance to allow method chaining at the dispatch site.
     */
    public function withTenantContext(): static
    {
        $this->tenancy = resolve(TenancyInterface::class)->tenancyPayload();

        return $this;
    }
}
