<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy;

use Cline\Tenancy\Contracts\LandlordInterface;

/**
 * Immutable value object representing an active landlord context.
 *
 * Wraps a `LandlordInterface` model and exposes a stable, read-only API for
 * accessing the landlord's identity and serialised payload. Instances are pushed
 * onto the tenancy stack by `TenancyInterface::runAsLandlord()` and popped when
 * the scope closes.
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class LandlordContext
{
    /**
     * @param LandlordInterface $landlord The underlying landlord model that this context wraps.
     */
    public function __construct(
        public LandlordInterface $landlord,
    ) {}

    /**
     * Return the landlord's primary key.
     */
    public function id(): int|string
    {
        return $this->landlord->id();
    }

    /**
     * Return the landlord's URL-safe slug.
     */
    public function slug(): string
    {
        return $this->landlord->slug();
    }

    /**
     * Return a serialisable payload representing this landlord context.
     *
     * Merges the landlord's `id` and `slug` with the additional data returned
     * by `LandlordInterface::getContextPayload()`. Used when serialising the
     * active context alongside queued jobs.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'id' => $this->id(),
            'slug' => $this->slug(),
        ] + $this->landlord->getContextPayload();
    }
}
