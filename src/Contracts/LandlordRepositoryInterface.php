<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Contracts;

/**
 * Persistence contract for landlord records.
 *
 * Decouples the tenancy layer from any specific storage backend. The default
 * implementation uses Eloquent, but any data source — including external APIs
 * or in-memory stores — can be substituted by binding an alternative
 * implementation in the service container.
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface LandlordRepositoryInterface
{
    /**
     * Find a landlord by its primary key.
     *
     * @param  int|string             $id The landlord's primary key value.
     * @return null|LandlordInterface The matching landlord, or `null` if not found.
     */
    public function findById(int|string $id): ?LandlordInterface;

    /**
     * Find a landlord by its URL-safe slug.
     *
     * @param  string                 $slug The unique slug identifying the landlord.
     * @return null|LandlordInterface The matching landlord, or `null` if not found.
     */
    public function findBySlug(string $slug): ?LandlordInterface;

    /**
     * Find a landlord associated with the given domain name.
     *
     * Used by domain-based resolvers to map an incoming request hostname to a
     * landlord. The lookup strategy (exact match, subdomain, wildcard) is
     * left to the implementation.
     *
     * @param  string                 $domain The fully-qualified domain or subdomain to search for.
     * @return null|LandlordInterface The matching landlord, or `null` if not found.
     */
    public function findByDomain(string $domain): ?LandlordInterface;

    /**
     * Find a landlord by an opaque identifier.
     *
     * Resolvers that receive identifiers from HTTP headers, path segments, or
     * session data call this method when the identifier type is not known in
     * advance. Implementations should treat the value as either a primary key
     * or a slug and return the first match.
     *
     * @param  int|string             $identifier A primary key or slug value.
     * @return null|LandlordInterface The matching landlord, or `null` if not found.
     */
    public function findByIdentifier(int|string $identifier): ?LandlordInterface;

    /**
     * Return all landlords in the system.
     *
     * Used by the scheduler and Artisan commands to iterate over every
     * landlord, for example to run migrations or scheduled tasks in each
     * landlord context.
     *
     * @return iterable<LandlordInterface> All persisted landlords.
     */
    public function all(): iterable;

    /**
     * Persist a new landlord with the given attributes.
     *
     * @param  array<string, mixed> $attributes Column-value pairs for the new landlord record.
     * @return LandlordInterface    The newly created landlord instance.
     */
    public function create(array $attributes): LandlordInterface;
}
