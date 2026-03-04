<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Contracts;

/**
 * Defines the contract for retrieving and creating tenant records.
 *
 * Implementations are responsible for querying the underlying persistence
 * layer (e.g. Eloquent, a cache, or an external API) and returning
 * objects that satisfy {@see TenantInterface}. The resolver layer depends
 * on this interface to locate a tenant from an incoming HTTP request.
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface TenantRepositoryInterface
{
    /**
     * Find a tenant by its primary key.
     *
     * @param  int|string           $id The tenant's primary key value.
     * @return null|TenantInterface The matching tenant, or null if not found.
     */
    public function findById(int|string $id): ?TenantInterface;

    /**
     * Find a tenant by its unique URL-safe slug.
     *
     * @param  string               $slug The tenant's slug (e.g. "acme-corp").
     * @return null|TenantInterface The matching tenant, or null if not found.
     */
    public function findBySlug(string $slug): ?TenantInterface;

    /**
     * Find a tenant by its associated domain name.
     *
     * The domain value should be a bare hostname without scheme or path
     * (e.g. "acme.example.com"). Implementations may normalise the value
     * before querying.
     *
     * @param  string               $domain The fully-qualified domain name to look up.
     * @return null|TenantInterface The matching tenant, or null if not found.
     */
    public function findByDomain(string $domain): ?TenantInterface;

    /**
     * Find a tenant by an arbitrary identifier such as an ID, slug, or domain.
     *
     * Implementations decide which fields to search. This method is
     * typically used by resolvers that receive a single opaque value from
     * the request and need to locate the correct tenant.
     *
     * @param  int|string           $identifier The value to search across supported fields.
     * @return null|TenantInterface The matching tenant, or null if not found.
     */
    public function findByIdentifier(int|string $identifier): ?TenantInterface;

    /**
     * Retrieve all tenants from the data store.
     *
     * @return iterable<TenantInterface> Every tenant known to the repository.
     */
    public function all(): iterable;

    /**
     * Create and persist a new tenant with the given attributes.
     *
     * @param  array<string, mixed> $attributes Column/attribute map for the new tenant record.
     * @return TenantInterface      The newly created tenant instance.
     */
    public function create(array $attributes): TenantInterface;
}
