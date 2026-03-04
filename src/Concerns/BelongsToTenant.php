<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Concerns;

use Cline\Tenancy\Contracts\TenantInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use function config;
use function is_string;

/**
 * Provides an Eloquent relationship from any model back to its owning tenant.
 *
 * Add this trait to any Eloquent model that stores a tenant foreign key column
 * and should expose a typed `tenant()` relationship. The foreign key column name
 * is read from `tenancy.scoping.tenant_foreign_key` (default: `tenant_id`), so
 * the relationship automatically reflects the same column used by `HasTenantId`.
 *
 * ```php
 * class Post extends Model
 * {
 *     use BelongsToTenant;
 * }
 *
 * $post->tenant; // returns the owning TenantInterface model
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
trait BelongsToTenant
{
    /**
     * Get the tenant that owns this model.
     *
     * Resolves the tenant model class from `tenancy.tenant_model` and the
     * foreign key from `tenancy.scoping.tenant_foreign_key`, falling back to
     * `tenant_id` when the configured value is absent or empty.
     *
     * @return BelongsTo<TenantInterface, $this>
     */
    public function tenant(): BelongsTo
    {
        /** @var class-string<TenantInterface> $tenantModel */
        $tenantModel = (string) config('tenancy.tenant_model');
        $tenantForeignKey = config('tenancy.scoping.tenant_foreign_key', 'tenant_id');

        if (!is_string($tenantForeignKey) || $tenantForeignKey === '') {
            $tenantForeignKey = 'tenant_id';
        }

        return $this->belongsTo($tenantModel, $tenantForeignKey);
    }
}
