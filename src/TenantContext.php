<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy;

use Cline\Tenancy\Contracts\TenantInterface;

/**
 * Immutable value object representing an active tenant context.
 *
 * Wraps a `TenantInterface` model and exposes a stable, read-only API for
 * accessing the tenant's identity and serialised payload. Instances are pushed
 * onto the tenancy stack by `TenancyInterface::runAsTenant()` and popped when
 * the scope closes.
 *
 * The `payload()` method merges the tenant's `id` and `slug` with any additional
 * data returned by `TenantInterface::getContextPayload()`. This merged payload is
 * consumed by tasks (e.g. `MapTenantConfigTask`) and serialised alongside queued
 * jobs when queue propagation is enabled.
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class TenantContext
{
    /**
     * Create a new tenant context wrapping the given tenant model.
     *
     * @param TenantInterface $tenant The underlying tenant model that this context wraps.
     */
    public function __construct(
        public TenantInterface $tenant,
    ) {}

    /**
     * Return the tenant's primary key.
     */
    public function id(): int|string
    {
        return $this->tenant->id();
    }

    /**
     * Return the tenant's URL-safe slug.
     */
    public function slug(): string
    {
        return $this->tenant->slug();
    }

    /**
     * Return a serialisable payload representing this tenant context.
     *
     * Merges the tenant's `id` and `slug` with the additional data returned
     * by `TenantInterface::getContextPayload()`. The `id` and `slug` keys take
     * precedence over anything returned by `getContextPayload()`. Used when
     * serialising the active context alongside queued jobs and when tasks read
     * tenant-specific values to apply to Laravel config.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'id' => $this->id(),
            'slug' => $this->slug(),
        ] + $this->tenant->getContextPayload();
    }
}
