<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Concerns;

use Cline\Tenancy\Contracts\TenancyInterface;
use Cline\Tenancy\Exceptions\MissingCurrentTenant;
use Illuminate\Database\Eloquent\Builder;

use function config;
use function is_string;
use function resolve;

/**
 * Automatically scopes Eloquent models to the current tenant.
 *
 * Attaches two Eloquent hooks when the trait is booted:
 *
 * - **creating**: stamps the tenant foreign key on new records using the active
 *   tenant resolved from `TenancyInterface`. If no tenant is active and
 *   `tenancy.scoping.require_current_tenant` is `true`, a
 *   `MissingCurrentTenant` exception is thrown instead of allowing the record
 *   to be saved without a tenant.
 *
 * - **global scope** (`tenant`): restricts all queries to rows belonging to the
 *   active tenant. The scope is skipped silently when no tenant is set, unless
 *   `require_current_tenant` is enabled.
 *
 * The foreign key column is read from `tenancy.scoping.tenant_foreign_key`
 * (default: `tenant_id`).
 *
 * ```php
 * class Post extends Model
 * {
 *     use HasTenantId;
 * }
 *
 * // Queries are automatically filtered by the active tenant.
 * $posts = Post::all();
 *
 * // Bypass the global scope when cross-tenant access is required.
 * $posts = Post::withoutTenantScope()->get();
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
trait HasTenantId
{
    /**
     * Boot the trait and register the creating listener and global tenant scope.
     *
     * Called automatically by Eloquent during model boot. Registers a `creating`
     * event listener that stamps the tenant foreign key, and adds a global query
     * scope named `tenant` that constrains all queries to the active tenant.
     *
     * @throws MissingCurrentTenant When no current tenant is set and
     *                              `tenancy.scoping.require_current_tenant` is `true`.
     */
    public static function bootHasTenantId(): void
    {
        static::creating(static function ($model): void {
            $tenantForeignKey = static::tenantForeignKey();

            if ($model->getAttribute($tenantForeignKey) !== null) {
                return;
            }

            $tenantId = resolve(TenancyInterface::class)->tenantId();

            if ($tenantId === null) {
                if ((bool) config('tenancy.scoping.require_current_tenant', false)) {
                    throw MissingCurrentTenant::forModel($model::class);
                }

                return;
            }

            $model->setAttribute($tenantForeignKey, $tenantId);
        });

        static::addGlobalScope('tenant', static function (Builder $builder): void {
            $tenantId = resolve(TenancyInterface::class)->tenantId();
            $tenantForeignKey = static::tenantForeignKey();

            if ($tenantId === null) {
                if ((bool) config('tenancy.scoping.require_current_tenant', false)) {
                    throw MissingCurrentTenant::forModel($builder->getModel()::class);
                }

                return;
            }

            $builder->where($builder->qualifyColumn($tenantForeignKey), $tenantId);
        });
    }

    /**
     * Scope a query to records belonging to a specific tenant, bypassing the
     * global tenant scope.
     *
     * Useful when a single query must access a tenant other than the one that
     * is currently active, such as during cross-tenant data migrations.
     *
     * @param  Builder<static> $query    The Eloquent query builder instance.
     * @param  int|string      $tenantId The id of the tenant to scope the query to.
     * @return Builder<static>
     */
    protected function scopeForTenant(Builder $query, int|string $tenantId): Builder
    {
        return $query->withoutGlobalScope('tenant')->where($query->qualifyColumn(static::tenantForeignKey()), $tenantId);
    }

    /**
     * Scope a query to remove the global tenant scope entirely.
     *
     * Use this when you need unrestricted access across all tenants, such as in
     * administrative queries or system-level operations. Prefer `runAsSystem()`
     * on `TenancyInterface` for broader cross-tenant operations.
     *
     * @param  Builder<static> $query The Eloquent query builder instance.
     * @return Builder<static>
     */
    protected function scopeWithoutTenantScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope('tenant');
    }

    /**
     * Resolve the tenant foreign key column name from configuration.
     *
     * Returns the value of `tenancy.scoping.tenant_foreign_key`, falling back
     * to `tenant_id` when the configured value is absent or an empty string.
     */
    private static function tenantForeignKey(): string
    {
        $tenantForeignKey = config('tenancy.scoping.tenant_foreign_key', 'tenant_id');

        if (!is_string($tenantForeignKey) || $tenantForeignKey === '') {
            return 'tenant_id';
        }

        return $tenantForeignKey;
    }
}
