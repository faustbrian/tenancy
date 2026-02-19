<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Repositories;

use Cline\Tenancy\Contracts\DomainAwareLandlordInterface;
use Cline\Tenancy\Contracts\LandlordInterface;
use Cline\Tenancy\Contracts\LandlordRepositoryInterface;
use Cline\Tenancy\Contracts\SynchronizesLandlordDomainLookupInterface;
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
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function now;

/**
 * Eloquent-backed implementation of the landlord repository.
 *
 * Persists and retrieves landlord records using the Eloquent ORM. The concrete
 * model class is read from `tenancy.landlord_model` at runtime, allowing
 * applications to swap in a custom model without rebinding the repository.
 *
 * Domain-to-landlord resolution follows a three-tier strategy:
 *
 * 1. **Domain lookup table** — a dedicated flat table (default: `landlord_domains`)
 *    that maps individual normalised domain strings to landlord IDs. Fastest path;
 *    enabled via `tenancy.landlord.domain_lookup.use_table`.
 * 2. **JSON column scan** — a `whereJsonContains` query against the landlord
 *    model's `domains` JSON column when the lookup table is unavailable or misses.
 * 3. **Cursor-based normalised scan** — iterates all landlords in memory and
 *    normalises each stored domain with {@see DomainNormalizer}, used as a last
 *    resort for domains that differ only in scheme or trailing dots.
 *
 * Results from step 1 can be cached. Configure `tenancy.landlord.domain_lookup.cache`
 * to enable caching, set the TTL, and choose a cache store.
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class EloquentLandlordRepository implements LandlordRepositoryInterface, SynchronizesLandlordDomainLookupInterface
{
    /**
     * Find a landlord by its primary key.
     *
     * @param  int|string             $id The landlord's primary key value.
     * @return null|LandlordInterface The matching landlord, or null if not found.
     */
    public function findById(int|string $id): ?LandlordInterface
    {
        return $this->query()->find($id);
    }

    /**
     * Find a landlord by its URL-safe slug.
     *
     * @param  string                 $slug The unique slug identifying the landlord (e.g. `"acme-corp"`).
     * @return null|LandlordInterface The matching landlord, or null if not found.
     */
    public function findBySlug(string $slug): ?LandlordInterface
    {
        return $this->query()->where('slug', $slug)->first();
    }

    /**
     * Find a landlord associated with the given domain name.
     *
     * Normalises the domain via {@see DomainNormalizer} before searching so
     * that scheme prefixes and inconsistent casing do not cause misses. Resolution
     * uses the three-tier strategy described in the class docblock. Returns null
     * when the domain cannot be normalised or no landlord matches.
     *
     * @param  string                 $domain The fully-qualified domain or subdomain to look up.
     * @return null|LandlordInterface The matching landlord, or null if not found.
     */
    public function findByDomain(string $domain): ?LandlordInterface
    {
        $normalizedDomain = DomainNormalizer::normalize($domain);

        if ($normalizedDomain === null) {
            return null;
        }

        $resolvedLandlordId = $this->resolveLandlordIdByDomain($normalizedDomain, $domain);

        if (!is_int($resolvedLandlordId) && !is_string($resolvedLandlordId)) {
            return null;
        }

        if ($resolvedLandlordId === '') {
            return null;
        }

        return $this->findById($resolvedLandlordId);
    }

    /**
     * Find a landlord by an opaque identifier.
     *
     * Integer identifiers are routed directly to {@see findById}. String
     * identifiers are first attempted as a slug and, if not found, retried as
     * a primary key string.
     *
     * @param  int|string             $identifier A primary key or slug value.
     * @return null|LandlordInterface The matching landlord, or null if not found.
     */
    public function findByIdentifier(int|string $identifier): ?LandlordInterface
    {
        if (is_int($identifier)) {
            return $this->findById($identifier);
        }

        return $this->findBySlug($identifier) ?? $this->findById($identifier);
    }

    /**
     * Return all landlords as a lazy cursor.
     *
     * Uses Eloquent's `cursor()` to stream results one-by-one, keeping memory
     * consumption flat regardless of the number of landlords.
     *
     * @return iterable<LandlordInterface> All persisted landlords.
     */
    public function all(): iterable
    {
        return $this->query()->cursor();
    }

    /**
     * Persist a new landlord and synchronise its domain lookup entries.
     *
     * Creates the landlord record, then writes rows to the domain lookup table
     * for any domains present in `$attributes['domains']`. This keeps the lookup
     * table consistent without requiring a separate call to {@see syncDomainLookup}.
     *
     * @param  array<string, mixed> $attributes Column-value pairs for the new landlord record.
     * @return LandlordInterface    The newly created landlord instance.
     */
    public function create(array $attributes): LandlordInterface
    {
        $landlord = $this->query()->create($attributes);
        $this->syncDomainLookupFromDomains(
            $landlord,
            $this->landlordDomainsFromAttributes($attributes),
        );

        return $landlord;
    }

    /**
     * Rebuild the domain lookup table entries for the given landlord.
     *
     * Deletes existing rows for the landlord's ID, then re-inserts one row per
     * normalised domain. Call this after updating a landlord's domain list to
     * keep the lookup table in sync. Has no effect when the domain lookup table
     * is disabled via `tenancy.landlord.domain_lookup.use_table`.
     *
     * @param LandlordInterface $landlord The landlord whose domain entries should be rebuilt.
     */
    public function syncDomainLookup(LandlordInterface $landlord): void
    {
        $this->syncDomainLookupFromDomains(
            $landlord,
            $this->landlordDomains($landlord),
        );
    }

    /**
     * Remove all domain lookup entries for the given landlord ID.
     *
     * Used when a landlord is deleted or its domain list is cleared. Has no
     * effect when the domain lookup table is disabled or the table does not
     * yet exist.
     *
     * @param int|string $landlordId The primary key of the landlord whose entries should be removed.
     */
    public function purgeDomainLookup(int|string $landlordId): void
    {
        if (!$this->useDomainLookupTable()) {
            return;
        }

        try {
            $this->domainTableQuery()->where('landlord_id', $landlordId)->delete();
        } catch (QueryException) {
            return;
        }
    }

    /**
     * Return a new Eloquent query builder scoped to the configured landlord model.
     *
     * @return Builder<LandlordInterface&Model>
     */
    private function query()
    {
        $model = $this->landlordModel();

        return $model->newQuery();
    }

    /**
     * Instantiate the configured landlord model class.
     *
     * Reads `tenancy.landlord_model` from config and constructs a fresh instance.
     * Falls back to `LandlordInterface::class` as the class name if the config
     * value is absent, which will cause an instantiation error and surface the
     * misconfiguration early.
     *
     * @return LandlordInterface&Model
     */
    private function landlordModel(): Model
    {
        /** @var class-string<LandlordInterface&Model> $modelClass */
        $modelClass = config('tenancy.landlord_model', LandlordInterface::class);

        return new $modelClass();
    }

    /**
     * Attempt to find a landlord via the domain lookup table.
     *
     * Returns null immediately when the lookup table is disabled or a database
     * error occurs, allowing callers to fall back to the JSON column strategy.
     *
     * @param  string                 $domain The normalised domain to query.
     * @return null|LandlordInterface The matching landlord, or null if not found.
     */
    private function findByDomainTable(string $domain): ?LandlordInterface
    {
        if (!$this->useDomainLookupTable()) {
            return null;
        }

        try {
            $tableQuery = $this->domainTableQuery();
            $landlordId = $tableQuery->where('domain', $domain)->value('landlord_id');
        } catch (QueryException) {
            return null;
        }

        if (!is_int($landlordId) && !is_string($landlordId)) {
            return null;
        }

        if ($landlordId === '') {
            return null;
        }

        return $this->findById($landlordId);
    }

    /**
     * Resolve a landlord ID from a normalised domain, using the cache when enabled.
     *
     * Wraps {@see resolveLandlordIdByDomainWithoutCache} with the configured
     * cache store and TTL. The cache key is built from `tenancy.landlord.domain_lookup.cache.prefix`
     * concatenated with the normalised domain.
     *
     * @param  string          $normalizedDomain The normalised (lowercased, trimmed) domain string.
     * @param  string          $originalDomain   The raw domain as received, used as a fallback search term.
     * @return null|int|string The landlord's primary key, or null if not found.
     */
    private function resolveLandlordIdByDomain(string $normalizedDomain, string $originalDomain): int|string|null
    {
        if (!$this->shouldCacheDomainLookups()) {
            return $this->resolveLandlordIdByDomainWithoutCache($normalizedDomain, $originalDomain);
        }

        $cachedLandlordId = $this->cacheStore()->remember(
            $this->cacheKey($normalizedDomain),
            $this->cacheTtlSeconds(),
            fn (): int|string|null => $this->resolveLandlordIdByDomainWithoutCache($normalizedDomain, $originalDomain),
        );

        if (!is_int($cachedLandlordId) && !is_string($cachedLandlordId)) {
            return null;
        }

        return $cachedLandlordId === '' ? null : $cachedLandlordId;
    }

    /**
     * Resolve a landlord ID from a domain without consulting the cache.
     *
     * Executes the three-tier resolution strategy in order:
     * 1. Domain lookup table (fast flat-table query).
     * 2. JSON `whereJsonContains` against the landlord model's `domains` column,
     *    falling back to the original (un-normalised) domain when the normalised
     *    form yields no result.
     * 3. In-memory cursor scan with per-domain normalisation as a last resort.
     *
     * @param  string          $normalizedDomain The normalised domain string.
     * @param  string          $originalDomain   The raw domain as received, used in fallback JSON query.
     * @return null|int|string The landlord's primary key, or null if resolution fails.
     */
    private function resolveLandlordIdByDomainWithoutCache(string $normalizedDomain, string $originalDomain): int|string|null
    {
        $landlord = $this->findByDomainTable($normalizedDomain);

        if ($landlord instanceof LandlordInterface) {
            return $landlord->id();
        }

        $landlord = $this->query()->whereJsonContains('domains', $normalizedDomain)->first();

        if (!$landlord instanceof LandlordInterface && $normalizedDomain !== $originalDomain) {
            $landlord = $this->query()->whereJsonContains('domains', $originalDomain)->first();
        }

        if ($landlord instanceof LandlordInterface) {
            return $landlord->id();
        }

        $landlord = $this->findByNormalizedJsonDomain($normalizedDomain);

        if (!$landlord instanceof LandlordInterface) {
            return null;
        }

        return $landlord->id();
    }

    /**
     * Extract and validate domain strings from a landlord instance.
     *
     * Prefers the {@see DomainAwareLandlordInterface::domains()} method when
     * available. Falls back to reading `domains` from the context payload for
     * landlord models that do not implement the domain-aware interface.
     * Empty strings are filtered from the result.
     *
     * @param  LandlordInterface  $landlord The landlord to extract domains from.
     * @return array<int, string> Validated, non-empty domain strings.
     */
    private function landlordDomains(LandlordInterface $landlord): array
    {
        if ($landlord instanceof DomainAwareLandlordInterface) {
            return array_values(array_filter($landlord->domains(), static fn (string $domain): bool => $domain !== ''));
        }

        $payload = $landlord->getContextPayload();
        $domains = $payload['domains'] ?? null;

        if (!is_array($domains)) {
            return [];
        }

        return array_values(array_filter($domains, static fn (mixed $domain): bool => is_string($domain) && $domain !== ''));
    }

    /**
     * Extract and validate domain strings from a creation attributes array.
     *
     * Used during {@see create} to derive domains for the initial lookup table
     * sync from the raw attribute map before the model is instantiated.
     *
     * @param  array<string, mixed> $attributes The attributes passed to create().
     * @return array<int, string>   Validated, non-empty domain strings.
     */
    private function landlordDomainsFromAttributes(array $attributes): array
    {
        if (!isset($attributes['domains']) || !is_array($attributes['domains'])) {
            return [];
        }

        return array_values(array_filter($attributes['domains'], static fn (mixed $domain): bool => is_string($domain) && $domain !== ''));
    }

    /**
     * Replace the domain lookup table entries for the given landlord.
     *
     * Deletes all existing rows for the landlord's ID, normalises each supplied
     * domain via {@see DomainNormalizer}, and bulk-inserts the resulting rows.
     * Domains that cannot be normalised are silently skipped. The entire
     * operation is a no-op when the lookup table is disabled or a database
     * error occurs.
     *
     * @param LandlordInterface  $landlord The landlord whose lookup rows should be rebuilt.
     * @param array<int, string> $domains  The raw domain strings to write to the table.
     */
    private function syncDomainLookupFromDomains(LandlordInterface $landlord, array $domains): void
    {
        if (!$this->useDomainLookupTable()) {
            return;
        }

        try {
            $table = $this->domainTableQuery();
            $table->where('landlord_id', $landlord->id())->delete();
        } catch (QueryException) {
            return;
        }

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
                'landlord_id' => $landlord->id(),
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
     * Find a landlord by scanning all stored domains after normalisation.
     *
     * This is the slowest resolution path — it cursors through every landlord
     * and normalises each of their stored domain strings until a match is found.
     * It exists to handle domains that are stored with inconsistent casing or
     * scheme prefixes that `whereJsonContains` would not match.
     *
     * @param  string                 $domain The already-normalised domain to match against.
     * @return null|LandlordInterface The first landlord whose domains contain a normalised match, or null.
     */
    private function findByNormalizedJsonDomain(string $domain): ?LandlordInterface
    {
        foreach ($this->query()->cursor() as $landlord) {
            $domains = null;

            if ($landlord instanceof DomainAwareLandlordInterface) {
                $domains = $landlord->domains();
            }

            if (!is_array($domains)) {
                $payload = $landlord->getContextPayload();
                $domains = $payload['domains'] ?? null;
            }

            if (!is_array($domains)) {
                continue;
            }

            foreach ($domains as $candidateDomain) {
                if (!is_string($candidateDomain)) {
                    continue;
                }

                if (DomainNormalizer::normalize($candidateDomain) === $domain) {
                    return $landlord;
                }
            }
        }

        return null;
    }

    /**
     * Return a query builder for the domain lookup table.
     *
     * The table name is read from `tenancy.table_names.landlord_domains`
     * (default: `landlord_domains`) and the connection from `tenancy.connection`.
     * When no explicit connection is configured the default database connection
     * is used.
     */
    private function domainTableQuery(): \Illuminate\Database\Query\Builder
    {
        $table = config('tenancy.table_names.landlord_domains', 'landlord_domains');
        $connection = config('tenancy.connection');

        if (!is_string($table) || $table === '') {
            $table = 'landlord_domains';
        }

        if (!is_string($connection) || $connection === '') {
            return DB::table($table);
        }

        return DB::connection($connection)->table($table);
    }

    /**
     * Determine whether the domain lookup table is enabled.
     *
     * Reads `tenancy.landlord.domain_lookup.use_table` from config, defaulting
     * to `true`. Disable this when the domain lookup table has not been migrated.
     *
     * @return bool True when the lookup table should be used.
     */
    private function useDomainLookupTable(): bool
    {
        return (bool) config('tenancy.landlord.domain_lookup.use_table', true);
    }

    /**
     * Determine whether domain-to-landlord lookups should be cached.
     *
     * Reads `tenancy.landlord.domain_lookup.cache.enabled`. Only returns true
     * when the config value is explicitly boolean `true` to prevent accidental
     * activation via truthy non-boolean values.
     *
     * @return bool True when caching is enabled.
     */
    private function shouldCacheDomainLookups(): bool
    {
        $enabled = config('tenancy.landlord.domain_lookup.cache.enabled', false);

        return is_bool($enabled) && $enabled;
    }

    /**
     * Return the cache TTL in seconds for domain lookup results.
     *
     * Reads `tenancy.landlord.domain_lookup.cache.ttl_seconds`, defaulting to
     * 60 seconds. Any non-positive or non-integer value is coerced to 60.
     *
     * @return int Positive TTL value in seconds.
     */
    private function cacheTtlSeconds(): int
    {
        $ttl = config('tenancy.landlord.domain_lookup.cache.ttl_seconds', 60);

        return is_int($ttl) && $ttl > 0 ? $ttl : 60;
    }

    /**
     * Build the cache key for a given normalised domain.
     *
     * Combines the configured prefix (`tenancy.landlord.domain_lookup.cache.prefix`,
     * defaulting to `tenancy:domain:landlord:`) with the normalised domain string.
     *
     * @param  string $normalizedDomain The normalised domain string.
     * @return string The fully-qualified cache key.
     */
    private function cacheKey(string $normalizedDomain): string
    {
        $prefix = config('tenancy.landlord.domain_lookup.cache.prefix', 'tenancy:domain:landlord:');

        if (!is_string($prefix) || $prefix === '') {
            $prefix = 'tenancy:domain:landlord:';
        }

        return $prefix.$normalizedDomain;
    }

    /**
     * Return the cache store instance configured for domain lookups.
     *
     * Reads `tenancy.landlord.domain_lookup.cache.store`. When absent or empty
     * the application's default cache store is used.
     *
     * @return Repository The configured cache store.
     */
    private function cacheStore(): Repository
    {
        $store = config('tenancy.landlord.domain_lookup.cache.store');

        if (!is_string($store) || $store === '') {
            return Cache::store();
        }

        return Cache::store($store);
    }
}
