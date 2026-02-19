<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Contracts;

/**
 * Contract for tenants that expose a named feature flag map.
 *
 * Implement this interface on a tenant model to allow the application to gate
 * functionality on a per-tenant basis. Features are keyed by a string name
 * and may carry an arbitrary value — a boolean for simple on/off flags, or a
 * scalar/array for configurable feature parameters.
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface FeatureAwareTenantInterface
{
    /**
     * Return the feature flag map for this tenant.
     *
     * Keys are feature names (e.g. `"billing"`, `"api_access"`). Values may be
     * booleans for simple toggles or any mixed type for parameterised features.
     * An empty array indicates that no custom features are configured for the
     * tenant and the application should fall back to its global defaults.
     *
     * @return array<string, mixed> Feature name-to-value map for this tenant.
     */
    public function features(): array;
}
