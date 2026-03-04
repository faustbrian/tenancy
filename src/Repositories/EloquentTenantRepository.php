<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Repositories;

use Cline\Tenancy\Contracts\SynchronizesTenantDomainLookupInterface;
use Cline\Tenancy\Contracts\TenantInterface;
use Cline\Tenancy\Contracts\TenantRepositoryInterface;
use Cline\Tenancy\Support\DomainNormalizer;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

use function array_filter;
use function array_values;
use function config;
use function is_bool;
use function is_int;
use function is_string;
use function now;

/**
 * Eloquent-backed implementation of the tenant repository.
 *
 * Persists and retrieves tenant records using the Eloquent ORM. The concrete
 * model class is read from `tenancy.tenant_model` at runtime, allowing
 * applications to swap in a custom model without rebinding the repository.
 *
 * Domain-to-tenant resolution follows a three-tier strategy:
 *
 * 1. **Domain lookup table** — a dedicated flat table (default: `tenant_domains`)
 *    that maps individual normalised domain strings to tenant IDs. Fastest path;
 *    enabled via `tenancy.domain_lookup.use_table`.
 * 2. **JSON column scan** — a `whereJsonContains` query against the tenant
 *    model's `domains` JSON column when the lookup table is unavailable or misses.
 * 3. **Cursor-based normalised scan** — iterates all tenants in memory and
 *    normalises each stored domain with {@see DomainNormalizer}, used as a last
 *    resort for domains that differ only in scheme or trailing dots.
 *
 * Results from step 1 can be cached. Configure `tenancy.domain_lookup.cache`
 * to enable caching, set the TTL, and choose a cache store.
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class EloquentTenantRepository implements SynchronizesTenantDomainLookupInterface, TenantRepositoryInterface
{
    /**
     * Find a tenant by its primary key.
     *
     * @param  int|string           $id The tenant's primary key value.
     * @return null|TenantInterface The matching tenant, or null if not found.
     */
    public function findById(int|string $id): ?TenantInterface
    {
        return $this->query()->find($id);
    }

    /**
     * Find a tenant by its URL-safe slug.
     *
     * @param  string               $slug The unique slug identifying the tenant (e.g. `"acme-corp"`).
     * @return null|TenantInterface The matching tenant, or null if not found.
     */
    public function findBySlug(string $slug): ?TenantInterface
    {
        return $this->query()->where('slug', $slug)->first();
    }

    /**
     * Find a tenant associated with the given domain name.
     *
     * Normalises the domain via {@see DomainNormalizer} before searching so
     * that scheme prefixes and inconsistent casing do not cause misses. Resolution
     * uses the three-tier strategy described in the class docblock. Returns null
     * when the domain cannot be normalised or no tenant matches.
     *
     * @param  string               $domain The fully-qualified domain or subdomain to look up.
     * @return null|TenantInterface The matching tenant, or null if not found.
     */
    public function findByDomain(string $domain): ?TenantInterface
    {
        $normalizedDomain = DomainNormalizer::normalize($domain);

        if ($normalizedDomain === null) {
            return null;
        }

        $resolvedTenantId = $this->resolveTenantIdByDomain($normalizedDomain, $domain);

        if (!is_int($resolvedTenantId) && !is_string($resolvedTenantId)) {
            return null;
        }

        if ($resolvedTenantId === '') {
            return null;
        }

        return $this->findById($resolvedTenantId);
    }

    /**
     * Find a tenant by an opaque identifier.
     *
     * Integer identifiers are routed directly to {@see findById}. String
     * identifiers are first attempted as a slug and, if not found, retried as
     * a primary key string.
     *
     * @param  int|string           $identifier A primary key or slug value.
     * @return null|TenantInterface The matching tenant, or null if not found.
     */
    public function findByIdentifier(int|string $identifier): ?TenantInterface
    {
        if (is_int($identifier)) {
            return $this->findById($identifier);
        }

        return $this->findBySlug($identifier) ?? $this->findById($identifier);
    }

    /**
     * Return all tenants as a lazy cursor.
     *
     * Uses Eloquent's `cursor()` to stream results one-by-one, keeping memory
     * consumption flat regardless of the number of tenants.
     *
     * @return iterable<TenantInterface> All persisted tenants.
     */
    public function all(): iterable
    {
        return $this->query()->cursor();
    }

    /**
     * Persist a new tenant and synchronise its domain lookup entries.
     *
     * Creates the tenant record, then immediately calls {@see syncDomainLookup}
     * to write the corresponding rows into the domain lookup table. This keeps
     * the lookup table consistent without requiring a separate sync call.
     *
     * @param  array<string, mixed> $attributes Column-value pairs for the new tenant record.
     * @return TenantInterface      The newly created tenant instance.
     */
    public function create(array $attributes): TenantInterface
    {
        $tenant = $this->query()->create($attributes);
        $this->syncDomainLookup($tenant);

        return $tenant;
    }

    /**
     * Rebuild the domain lookup table entries for the given tenant.
     *
     * Deletes existing rows for the tenant's ID, then re-inserts one row per
     * normalised domain. Call this after updating a tenant's domain list to keep
     * the lookup table in sync. Has no effect when the domain lookup table is
     * disabled via `tenancy.domain_lookup.use_table` or a database error occurs.
     *
     * @param TenantInterface $tenant The tenant whose domain entries should be rebuilt.
     */
    public function syncDomainLookup(TenantInterface $tenant): void
    {
        if (!$this->useDomainLookupTable()) {
            return;
        }

        try {
            $table = $this->domainTableQuery();
            $table->where('tenant_id', $tenant->id())->delete();
        } catch (QueryException) {
            return;
        }

        $domains = array_values(array_filter($tenant->domains(), static fn (string $domain): bool => $domain !== ''));

        if ($domains === []) {
            return;
        }

        $rows = [];

        foreach ($domains as $domain) {
            $normalizedDomain = DomainNormalizer::normalize($domain);

            if ($normalizedDomain === null) {
                continue;
            }

            $rows[] = [
                'tenant_id' => $tenant->id(),
                'domain' => $normalizedDomain,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows === []) {
            return;
        }

        try {
            $table->insert($rows);
        } catch (QueryException) {
            return;
        }
    }

    /**
     * Remove all domain lookup entries for the given tenant ID.
     *
     * Used when a tenant is deleted or its domain list is cleared. Has no
     * effect when the domain lookup table is disabled or the table does not
     * yet exist.
     *
     * @param int|string $tenantId The primary key of the tenant whose entries should be removed.
     */
    public function purgeDomainLookup(int|string $tenantId): void
    {
        if (!$this->useDomainLookupTable()) {
            return;
        }

        try {
            $this->domainTableQuery()->where('tenant_id', $tenantId)->delete();
        } catch (QueryException) {
            return;
        }
    }

    /**
     * Return a new Eloquent query builder scoped to the configured tenant model.
     *
     * @return Builder<Model&TenantInterface>
     */
    private function query()
    {
        $model = $this->tenantModel();

        return $model->newQuery();
    }

    /**
     * Instantiate the configured tenant model class.
     *
     * Reads `tenancy.tenant_model` from config and constructs a fresh instance.
     * Falls back to `TenantInterface::class` as the class name if the config
     * value is absent, which will surface the misconfiguration as an
     * instantiation error early.
     *
     * @return Model&TenantInterface
     */
    private function tenantModel(): Model
    {
        /** @var class-string<Model&TenantInterface> $modelClass */
        $modelClass = config('tenancy.tenant_model', TenantInterface::class);

        return new $modelClass();
    }

    /**
     * Attempt to find a tenant via the domain lookup table.
     *
     * Returns null immediately when the lookup table is disabled or a database
     * error occurs, allowing callers to fall back to the JSON column strategy.
     *
     * @param  string               $domain The normalised domain to query.
     * @return null|TenantInterface The matching tenant, or null if not found.
     */
    private function findByDomainTable(string $domain): ?TenantInterface
    {
        if (!$this->useDomainLookupTable()) {
            return null;
        }

        try {
            $tableQuery = $this->domainTableQuery();
            $tenantId = $tableQuery->where('domain', $domain)->value('tenant_id');
        } catch (QueryException) {
            return null;
        }

        if (!is_int($tenantId) && !is_string($tenantId)) {
            return null;
        }

        if ($tenantId === '') {
            return null;
        }

        return $this->findById($tenantId);
    }

    /**
     * Resolve a tenant ID from a normalised domain, using the cache when enabled.
     *
     * Wraps {@see resolveTenantIdByDomainWithoutCache} with the configured cache
     * store and TTL. The cache key is built from `tenancy.domain_lookup.cache.prefix`
     * concatenated with the normalised domain.
     *
     * @param  string          $normalizedDomain The normalised (lowercased, trimmed) domain string.
     * @param  string          $originalDomain   The raw domain as received, used as a fallback search term.
     * @return null|int|string The tenant's primary key, or null if not found.
     */
    private function resolveTenantIdByDomain(string $normalizedDomain, string $originalDomain): int|string|null
    {
        if (!$this->shouldCacheDomainLookups()) {
            return $this->resolveTenantIdByDomainWithoutCache($normalizedDomain, $originalDomain);
        }

        $cachedTenantId = $this->cacheStore()->remember(
            $this->cacheKey($normalizedDomain),
            $this->cacheTtlSeconds(),
            fn (): int|string|null => $this->resolveTenantIdByDomainWithoutCache($normalizedDomain, $originalDomain),
        );

        if (!is_int($cachedTenantId) && !is_string($cachedTenantId)) {
            return null;
        }

        return $cachedTenantId === '' ? null : $cachedTenantId;
    }

    /**
     * Resolve a tenant ID from a domain without consulting the cache.
     *
     * Executes the three-tier resolution strategy in order:
     * 1. Domain lookup table (fast flat-table query).
     * 2. JSON `whereJsonContains` against the tenant model's `domains` column,
     *    falling back to the original (un-normalised) domain when the normalised
     *    form yields no result.
     * 3. In-memory cursor scan with per-domain normalisation as a last resort.
     *
     * @param  string          $normalizedDomain The normalised domain string.
     * @param  string          $originalDomain   The raw domain as received, used in fallback JSON query.
     * @return null|int|string The tenant's primary key, or null if resolution fails.
     */
    private function resolveTenantIdByDomainWithoutCache(string $normalizedDomain, string $originalDomain): int|string|null
    {
        $tenant = $this->findByDomainTable($normalizedDomain);

        if ($tenant instanceof TenantInterface) {
            return $tenant->id();
        }

        $tenant = $this->query()->whereJsonContains('domains', $normalizedDomain)->first();

        if (!$tenant instanceof TenantInterface && $normalizedDomain !== $originalDomain) {
            $tenant = $this->query()->whereJsonContains('domains', $originalDomain)->first();
        }

        if ($tenant instanceof TenantInterface) {
            return $tenant->id();
        }

        $tenant = $this->findByNormalizedJsonDomain($normalizedDomain);

        if (!$tenant instanceof TenantInterface) {
            return null;
        }

        return $tenant->id();
    }

    /**
     * Find a tenant by scanning all stored domains after normalisation.
     *
     * This is the slowest resolution path — it cursors through every tenant
     * and normalises each of their stored domain strings until a match is found.
     * It exists to handle domains stored with inconsistent casing or scheme
     * prefixes that `whereJsonContains` would not match.
     *
     * @param  string               $domain The already-normalised domain to match against.
     * @return null|TenantInterface The first tenant whose domains contain a normalised match, or null.
     */
    private function findByNormalizedJsonDomain(string $domain): ?TenantInterface
    {
        foreach ($this->query()->cursor() as $tenant) {
            foreach ($tenant->domains() as $candidateDomain) {
                if (DomainNormalizer::normalize($candidateDomain) === $domain) {
                    return $tenant;
                }
            }
        }

        return null;
    }

    /**
     * Return a query builder for the domain lookup table.
     *
     * The table name is read from `tenancy.table_names.tenant_domains`
     * (default: `tenant_domains`) and the connection from `tenancy.connection`.
     * When no explicit connection is configured the default database connection
     * is used.
     */
    private function domainTableQuery(): \Illuminate\Database\Query\Builder
    {
        $table = config('tenancy.table_names.tenant_domains', 'tenant_domains');
        $connection = config('tenancy.connection');

        if (!is_string($table) || $table === '') {
            $table = 'tenant_domains';
        }

        if (!is_string($connection) || $connection === '') {
            return DB::table($table);
        }

        return DB::connection($connection)->table($table);
    }

    /**
     * Determine whether the domain lookup table is enabled.
     *
     * Reads `tenancy.domain_lookup.use_table` from config, defaulting to `true`.
     * Disable this when the domain lookup table has not been migrated.
     *
     * @return bool True when the lookup table should be used.
     */
    private function useDomainLookupTable(): bool
    {
        return (bool) config('tenancy.domain_lookup.use_table', true);
    }

    /**
     * Determine whether domain-to-tenant lookups should be cached.
     *
     * Reads `tenancy.domain_lookup.cache.enabled`. Only returns true when the
     * config value is explicitly boolean `true` to prevent accidental activation
     * via truthy non-boolean values.
     *
     * @return bool True when caching is enabled.
     */
    private function shouldCacheDomainLookups(): bool
    {
        $enabled = config('tenancy.domain_lookup.cache.enabled', false);

        return is_bool($enabled) && $enabled;
    }

    /**
     * Return the cache TTL in seconds for domain lookup results.
     *
     * Reads `tenancy.domain_lookup.cache.ttl_seconds`, defaulting to 60 seconds.
     * Any non-positive or non-integer value is coerced to 60.
     *
     * @return int Positive TTL value in seconds.
     */
    private function cacheTtlSeconds(): int
    {
        $ttl = config('tenancy.domain_lookup.cache.ttl_seconds', 60);

        return is_int($ttl) && $ttl > 0 ? $ttl : 60;
    }

    /**
     * Build the cache key for a given normalised domain.
     *
     * Combines the configured prefix (`tenancy.domain_lookup.cache.prefix`,
     * defaulting to `tenancy:domain:tenant:`) with the normalised domain string.
     *
     * @param  string $normalizedDomain The normalised domain string.
     * @return string The fully-qualified cache key.
     */
    private function cacheKey(string $normalizedDomain): string
    {
        $prefix = config('tenancy.domain_lookup.cache.prefix', 'tenancy:domain:tenant:');

        if (!is_string($prefix) || $prefix === '') {
            $prefix = 'tenancy:domain:tenant:';
        }

        return $prefix.$normalizedDomain;
    }

    /**
     * Return the cache store instance configured for domain lookups.
     *
     * Reads `tenancy.domain_lookup.cache.store`. When absent or empty the
     * application's default cache store is used.
     *
     * @return Repository The configured cache store.
     */
    private function cacheStore(): Repository
    {
        $store = config('tenancy.domain_lookup.cache.store');

        if (!is_string($store) || $store === '') {
            return Cache::store();
        }

        return Cache::store($store);
    }
}
