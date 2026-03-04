<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Support;

use Cline\Tenancy\Contracts\TenancyInterface;
use Cline\Tenancy\Contracts\TenantImpersonationManagerInterface;
use Cline\Tenancy\Contracts\TenantInterface;
use Cline\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

use function bin2hex;
use function config;
use function is_array;
use function is_int;
use function is_string;
use function random_bytes;
use function sprintf;

/**
 * Issues and redeems short-lived impersonation tokens for tenant switching.
 *
 * Generates cryptographically random tokens that encode a tenant context, an
 * authenticated user ID, and an optional guard name. Tokens are stored in the
 * cache with a configurable TTL and consumed (deleted) on first use, preventing
 * replay attacks. The token flow is:
 *
 * 1. {@see issueToken()} — create a token and store its payload in the cache.
 * 2. {@see consumeToken()} — retrieve and immediately delete the payload.
 * 3. {@see applyToken()} — consume the token and activate the associated tenant context.
 *
 * Configuration keys:
 * - `tenancy.impersonation.query_parameter` — URL query parameter name (default: `tenant_impersonation`).
 * - `tenancy.impersonation.ttl_seconds` — token lifetime in seconds (default: `300`).
 * - `tenancy.impersonation.cache_prefix` — cache key prefix (default: `tenancy:impersonation:tenant:`).
 * - `tenancy.impersonation.cache_store` — cache store name; falls back to the default store when absent.
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class TenantImpersonationManager implements TenantImpersonationManagerInterface
{
    /**
     * Create a new tenant impersonation manager.
     *
     * @param TenancyInterface $tenancy Tenancy service used to resolve tenant contexts and activate them.
     */
    public function __construct(
        private TenancyInterface $tenancy,
    ) {}

    /**
     * Issue a short-lived impersonation token for the given tenant and user.
     *
     * Resolves the tenant to a {@see TenantContext}, generates a 64-character
     * hex token backed by 32 random bytes, and stores the payload in the cache
     * with the configured TTL. Returns null when the tenant cannot be resolved
     * to a valid context.
     *
     * @param  int|string|TenantContext|TenantInterface $tenant     The tenant to impersonate, accepted as a model,
     *                                                              context object, primary key, or string identifier.
     * @param  int|string                               $userId     The ID of the user who will act as the tenant.
     * @param  null|string                              $guard      The authentication guard to use, or null for the default.
     * @param  null|int                                 $ttlSeconds Token lifetime in seconds; falls back to
     *                                                              `tenancy.impersonation.ttl_seconds` when null.
     * @return null|string                              The issued token, or null when the tenant context cannot be resolved.
     */
    public function issueToken(
        TenantInterface|TenantContext|int|string $tenant,
        int|string $userId,
        ?string $guard = null,
        ?int $ttlSeconds = null,
    ): ?string {
        $context = $this->tenancy->tenant($tenant);

        if (!$context instanceof TenantContext) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $ttl = $ttlSeconds ?? $this->ttlSeconds();

        $this->cacheStore()->put(
            $this->cacheKey($token),
            [
                'tenant' => $context->payload(),
                'user_id' => $userId,
                'guard' => $guard,
                'issued_at' => Date::now()->getTimestamp(),
            ],
            $ttl,
        );

        return $token;
    }

    /**
     * Consume an impersonation token and return its payload.
     *
     * Retrieves the payload from the cache and immediately deletes it, ensuring
     * the token can only be used once. Returns null when the token does not exist
     * or has already been consumed.
     *
     * @param  string                    $token The token previously issued by {@see issueToken()}.
     * @return null|array<string, mixed> The stored payload, or null when the token is invalid or expired.
     */
    public function consumeToken(string $token): ?array
    {
        $key = $this->cacheKey($token);
        $store = $this->cacheStore();
        $payload = $store->get($key);

        $store->forget($key);

        if (!is_array($payload)) {
            return null;
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * Consume an impersonation token and activate the associated tenant context.
     *
     * Delegates to {@see consumeToken()} and, when the payload contains a valid
     * tenant sub-array, calls {@see TenancyInterface::fromTenantPayload()} to
     * switch the active tenant context. Returns the full payload on success, or
     * null when the token is invalid, expired, or missing the tenant data.
     *
     * @param  string                    $token The token previously issued by {@see issueToken()}.
     * @return null|array<string, mixed> The consumed payload, or null when the token cannot be applied.
     */
    public function applyToken(string $token): ?array
    {
        $payload = $this->consumeToken($token);

        if (!is_array($payload)) {
            return null;
        }

        $tenantPayload = $payload['tenant'] ?? null;

        if (!is_array($tenantPayload)) {
            return null;
        }

        /** @var array<string, mixed> $tenantPayload */
        $this->tenancy->fromTenantPayload($tenantPayload);

        return $payload;
    }

    /**
     * Return the URL query parameter name used to pass impersonation tokens.
     *
     * Reads from `tenancy.impersonation.query_parameter` and defaults to
     * `tenant_impersonation` when the config value is absent or not a non-empty string.
     *
     * @return string The query parameter name.
     */
    public function queryParameter(): string
    {
        $parameter = config('tenancy.impersonation.query_parameter', 'tenant_impersonation');

        return is_string($parameter) && $parameter !== '' ? $parameter : 'tenant_impersonation';
    }

    /**
     * Return the token TTL in seconds.
     *
     * Reads from `tenancy.impersonation.ttl_seconds` and defaults to `300` when
     * the config value is absent, not an integer, or not a positive number.
     *
     * @return int The token lifetime in seconds.
     */
    private function ttlSeconds(): int
    {
        $ttl = config('tenancy.impersonation.ttl_seconds', 300);

        return is_int($ttl) && $ttl > 0 ? $ttl : 300;
    }

    /**
     * Build the fully-prefixed cache key for a given token.
     *
     * Reads the prefix from `tenancy.impersonation.cache_prefix` and defaults to
     * `tenancy:impersonation:tenant:` when the config value is absent or invalid.
     *
     * @param  string $token The raw token string.
     * @return string The cache key used to store or retrieve the token payload.
     */
    private function cacheKey(string $token): string
    {
        $prefix = config('tenancy.impersonation.cache_prefix', 'tenancy:impersonation:tenant:');

        if (!is_string($prefix) || $prefix === '') {
            $prefix = 'tenancy:impersonation:tenant:';
        }

        return sprintf('%s%s', $prefix, $token);
    }

    /**
     * Return the cache store instance used for token storage.
     *
     * Reads the store name from `tenancy.impersonation.cache_store` and falls back
     * to the application's default cache store when the config value is absent or
     * not a non-empty string.
     *
     * @return Repository The cache store repository.
     */
    private function cacheStore(): Repository
    {
        $store = config('tenancy.impersonation.cache_store');

        if (!is_string($store) || $store === '') {
            return Cache::store();
        }

        return Cache::store($store);
    }
}
