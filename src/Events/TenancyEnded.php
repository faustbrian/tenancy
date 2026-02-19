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
 * Fired after the active tenant context has been cleared.
 *
 * Listen to this event to tear down tenant-scoped resources after the
 * tenancy session ends — for example, restoring the default database
 * connection, clearing tenant-prefixed caches, or resetting config values
 * that were overridden during the tenant's request lifecycle.
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class TenancyEnded
{
    /**
     * Create a new TenancyEnded event instance.
     *
     * @param null|TenantContext $previous The tenant context that was active before the
     *                                     session ended, or null if no tenant had been
     *                                     resolved for the current request.
     */
    public function __construct(
        public ?TenantContext $previous,
    ) {}
}
