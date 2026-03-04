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
 * Fired when the active landlord context is replaced with a different one.
 *
 * This event is dispatched during programmatic context switches — for
 * example, when running a landlord-scoped artisan command or impersonating
 * another landlord. Both the outgoing and incoming contexts are provided so
 * listeners can cleanly tear down and re-initialise landlord-scoped resources.
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class LandlordSwitched
{
    /**
     * Create a new LandlordSwitched event instance.
     *
     * @param null|LandlordContext $previous The landlord context that was active before the switch,
     *                                       or null if no landlord was set at the time.
     * @param null|LandlordContext $current  The landlord context that is now active, or null if the
     *                                       switch cleared the active context entirely.
     */
    public function __construct(
        public ?LandlordContext $previous,
        public ?LandlordContext $current,
    ) {}
}
