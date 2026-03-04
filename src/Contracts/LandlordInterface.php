<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Contracts;

/**
 * Core contract that every landlord model must satisfy.
 *
 * A landlord represents the top-level entity in a hierarchical multi-tenancy
 * setup — typically an organisation or platform customer that owns one or more
 * tenants. This interface defines the minimum surface required by the tenancy
 * layer to resolve, switch, and identify landlord contexts.
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface LandlordInterface
{
    /**
     * Return the primary key of this landlord.
     *
     * @return int|string The landlord's unique identifier.
     */
    public function id(): int|string;

    /**
     * Return the URL-safe slug that uniquely identifies this landlord.
     *
     * Used by resolvers and Artisan commands to look up a landlord by a
     * human-readable handle rather than a numeric id.
     *
     * @return string Non-empty slug string.
     */
    public function slug(): string;

    /**
     * Return the human-readable display name of this landlord.
     *
     * @return string Non-empty display name.
     */
    public function name(): string;

    /**
     * Return the serializable payload representing this landlord's context.
     *
     * The array is stored alongside queued jobs and other asynchronous payloads
     * so that the correct landlord context can be restored when the work is
     * processed. Keys and structure are implementation-defined but must be
     * sufficient to reconstruct the landlord via `TenancyInterface::fromLandlordPayload()`.
     *
     * @return array<string, mixed> Serializable context payload for this landlord.
     */
    public function getContextPayload(): array;
}
