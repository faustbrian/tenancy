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
 * Fired after the active landlord context has been cleared.
 *
 * Listen to this event to tear down any landlord-scoped resources —
 * such as resetting configuration overrides or clearing caches —
 * after the landlord session has ended.
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class LandlordEnded
{
    /**
     * Create a new LandlordEnded event instance.
     *
     * @param null|LandlordContext $previous The landlord context that was active before the
     *                                       session ended, or null if no landlord had been
     *                                       resolved for the current request.
     */
    public function __construct(
        public ?LandlordContext $previous,
    ) {}
}
