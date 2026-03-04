<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Contracts;

use Cline\Tenancy\TenantContext;

/**
 * Contract for issuing and consuming single-use tenant impersonation tokens.
 *
 * Tenant impersonation allows a privileged user (e.g. a super-admin) to act
 * as a specific user within a tenant's context without knowing that user's
 * credentials. The flow is:
 *
 * 1. A trusted caller issues a short-lived token via `issueToken()`, embedding
 *    the target tenant and user identity into the cache.
 * 2. The token is passed to the client (typically as a query parameter whose
 *    name is returned by `queryParameter()`).
 * 3. The `TenantImpersonation` middleware calls `applyToken()` on the incoming
 *    request, which consumes the token and activates the tenant context.
 *
 * Tokens are single-use: `consumeToken()` deletes the cache entry on first
 * access to prevent replay attacks. The TTL is configurable via
 * `tenancy.impersonation.ttl_seconds`.
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface TenantImpersonationManagerInterface
{
    /**
     * Issue a single-use impersonation token for the given tenant and user.
     *
     * Stores the tenant context payload, user id, guard name, and issue
     * timestamp in the cache under a cryptographically random key. Returns
     * the raw token string that should be passed to the client, or `null` if
     * the tenant cannot be resolved.
     *
     * @param  int|string|TenantContext|TenantInterface $tenant     The tenant the impersonation session will be scoped to.
     * @param  int|string                               $userId     The id of the user to authenticate during impersonation.
     * @param  null|string                              $guard      The authentication guard to use, or `null` for the default guard.
     * @param  null|int                                 $ttlSeconds Token lifetime in seconds, or `null` to use the configured default.
     * @return null|string                              The issued token, or `null` if the tenant could not be resolved.
     */
    public function issueToken(
        TenantInterface|TenantContext|int|string $tenant,
        int|string $userId,
        ?string $guard = null,
        ?int $ttlSeconds = null,
    ): ?string;

    /**
     * Retrieve and immediately invalidate the payload associated with a token.
     *
     * Deletes the cache entry on first read to ensure the token cannot be
     * replayed. Returns `null` when the token does not exist or has expired.
     *
     * @param  string                    $token The raw impersonation token issued by `issueToken()`.
     * @return null|array<string, mixed> The stored payload, or `null` if the token is invalid or expired.
     */
    public function consumeToken(string $token): ?array;

    /**
     * Consume the token and activate the associated tenant context.
     *
     * Calls `consumeToken()` internally, then restores the tenant context via
     * `TenancyInterface::fromTenantPayload()`. Returns the full payload so
     * that callers can authenticate the impersonated user from the `user_id`
     * and `guard` fields. Returns `null` if the token is invalid or expired.
     *
     * @param  string                    $token The raw impersonation token to apply.
     * @return null|array<string, mixed> The consumed payload (including `tenant`, `user_id`, `guard`, `issued_at`), or `null` if invalid.
     */
    public function applyToken(string $token): ?array;

    /**
     * Return the query parameter name used to pass impersonation tokens in URLs.
     *
     * Reads from `tenancy.impersonation.query_parameter` and falls back to
     * `"tenant_impersonation"` when the configuration value is absent or empty.
     *
     * @return string The query parameter name.
     */
    public function queryParameter(): string;
}
