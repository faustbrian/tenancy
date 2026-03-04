<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Events;

use Illuminate\Http\Request;

/**
 * Fired before the package attempts to resolve the active tenant from a request.
 *
 * Listen to this event to perform pre-resolution work such as injecting
 * resolution hints, logging the incoming request context, or short-circuiting
 * resolution for specific routes. The event is dispatched before any
 * {@see \Cline\Tenancy\Contracts\TenantResolverInterface} implementation runs.
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class TenantResolving
{
    /**
     * Create a new TenantResolving event instance.
     *
     * @param Request $request The incoming HTTP request from which the tenant will be resolved.
     */
    public function __construct(
        public Request $request,
    ) {}
}
