<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Events;

use Cline\Tenancy\TenantContext;

/**
 * Fired after a tenant has been successfully resolved and set as the active context.
 *
 * Listen to this event to bootstrap tenant-scoped resources such as switching
 * the database connection, applying tenant configuration overrides, or prefixing
 * cache keys. This event is the primary hook for initialising the tenant
 * environment at the start of a tenanted request.
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class TenantResolved
{
    /**
     * Create a new TenantResolved event instance.
     *
     * @param TenantContext      $current  The tenant context that has just been activated.
     * @param null|TenantContext $previous The tenant context that was active before this
     *                                     resolution, or null if no tenant was previously set.
     */
    public function __construct(
        public TenantContext $current,
        public ?TenantContext $previous,
    ) {}
}
