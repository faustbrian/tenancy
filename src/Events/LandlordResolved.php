<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Events;

use Cline\Tenancy\LandlordContext;

/**
 * Fired after a landlord has been successfully resolved and set as the active context.
 *
 * Listen to this event to bootstrap landlord-scoped resources such as
 * applying configuration overrides, switching cache prefixes, or
 * logging the context switch for audit purposes.
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class LandlordResolved
{
    /**
     * Create a new LandlordResolved event instance.
     *
     * @param LandlordContext      $current  The landlord context that has just been activated.
     * @param null|LandlordContext $previous The landlord context that was active before this
     *                                       resolution, or null if no landlord was previously set.
     */
    public function __construct(
        public LandlordContext $current,
        public ?LandlordContext $previous,
    ) {}
}
