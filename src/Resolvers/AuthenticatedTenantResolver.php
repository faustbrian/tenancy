<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Resolvers;

use Cline\Tenancy\Contracts\TenantInterface;
use Cline\Tenancy\Contracts\TenantRepositoryInterface;
use Cline\Tenancy\Contracts\TenantResolverInterface;
use Illuminate\Http\Request;

use function config;
use function data_get;
use function is_int;
use function is_string;

/**
 * Resolves the current tenant from the authenticated user.
 *
 * Uses a two-step strategy to locate the tenant:
 *
 * 1. If the authenticated user itself implements {@see TenantInterface}, it is
 *    returned directly — useful when users and tenants share the same model.
 * 2. Otherwise, a configurable attribute path is read from `tenancy.resolver.user_attribute`
 *    using Laravel's `data_get` helper (dot-notation supported). The resolved value
 *    is returned directly when it is already a `TenantInterface`, or used as an
 *    identifier to look up the tenant via the repository when it is a scalar.
 *
 * Returns null when no user is authenticated, the config key is absent, or the
 * attribute value cannot be resolved to a tenant.
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class AuthenticatedTenantResolver implements TenantResolverInterface
{
    /**
     * Create a new authenticated tenant resolver.
     *
     * @param TenantRepositoryInterface $tenants The repository used to look up a tenant by
     *                                           the scalar identifier extracted from the
     *                                           authenticated user's attribute.
     */
    public function __construct(
        private TenantRepositoryInterface $tenants,
    ) {}

    /**
     * Resolve the current tenant from the authenticated request user.
     *
     * @param  Request              $request The current HTTP request.
     * @return null|TenantInterface The resolved tenant, or null if resolution fails.
     */
    public function resolve(Request $request): ?TenantInterface
    {
        $user = $request->user();

        if ($user instanceof TenantInterface) {
            return $user;
        }

        if ($user === null) {
            return null;
        }

        $attribute = config('tenancy.resolver.user_attribute');

        if (!is_string($attribute) || $attribute === '') {
            return null;
        }

        $value = data_get($user, $attribute);

        if ($value instanceof TenantInterface) {
            return $value;
        }

        if (!is_int($value) && !is_string($value)) {
            return null;
        }

        return $this->tenants->findByIdentifier($value);
    }
}
