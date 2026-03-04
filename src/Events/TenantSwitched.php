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
 * Fired whenever the active tenant context changes.
 *
 * Dispatched by {@see \Cline\Tenancy\Tenancy} each time the tenant stack is
 * pushed or popped — including when a tenant is resolved from a request, when
 * {@see \Cline\Tenancy\Tenancy::runAsTenant()} enters or exits its scope, and
 * when {@see \Cline\Tenancy\Tenancy::forgetCurrentTenant()} clears the active
 * tenant. Both `$previous` and `$current` may be `null`, representing a
 * transition to or from the system (landlord-only) context.
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class TenantSwitched
{
    /**
     * Create a new tenant switched event.
     *
     * @param null|TenantContext $previous The tenant context that was active before the switch,
     *                                     or `null` when transitioning from the system context.
     * @param null|TenantContext $current  The tenant context that is now active after the switch,
     *                                     or `null` when transitioning back to the system context.
     */
    public function __construct(
        public ?TenantContext $previous,
        public ?TenantContext $current,
    ) {}
}
