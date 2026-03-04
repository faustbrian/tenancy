<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Resolvers;

use Cline\Tenancy\Contracts\LandlordInterface;
use Cline\Tenancy\Contracts\LandlordRepositoryInterface;
use Cline\Tenancy\Contracts\LandlordResolverInterface;
use Illuminate\Http\Request;

use function config;
use function data_get;
use function is_int;
use function is_string;

/**
 * Resolves the current landlord from the authenticated user.
 *
 * Uses a two-step strategy to locate the landlord:
 *
 * 1. If the authenticated user itself implements {@see LandlordInterface}, it is
 *    returned directly — useful when users and landlords share the same model.
 * 2. Otherwise, a configurable attribute path is read from `tenancy.landlord.resolver.user_attribute`
 *    using Laravel's `data_get` helper (dot-notation supported). The resolved value
 *    is returned directly when it is already a `LandlordInterface`, or used as an
 *    identifier to look up the landlord via the repository when it is a scalar.
 *
 * Returns null when no user is authenticated, the config key is absent, or the
 * attribute value cannot be resolved to a landlord.
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class AuthenticatedLandlordResolver implements LandlordResolverInterface
{
    /**
     * Create a new authenticated landlord resolver.
     *
     * @param LandlordRepositoryInterface $landlords The repository used to look up a landlord
     *                                               by the scalar identifier extracted from the
     *                                               authenticated user's attribute.
     */
    public function __construct(
        private LandlordRepositoryInterface $landlords,
    ) {}

    /**
     * Resolve the current landlord from the authenticated request user.
     *
     * @param  Request                $request The current HTTP request.
     * @return null|LandlordInterface The resolved landlord, or null if resolution fails.
     */
    public function resolve(Request $request): ?LandlordInterface
    {
        $user = $request->user();

        if ($user instanceof LandlordInterface) {
            return $user;
        }

        if ($user === null) {
            return null;
        }

        $attribute = config('tenancy.landlord.resolver.user_attribute');

        if (!is_string($attribute) || $attribute === '') {
            return null;
        }

        $value = data_get($user, $attribute);

        if ($value instanceof LandlordInterface) {
            return $value;
        }

        if (!is_int($value) && !is_string($value)) {
            return null;
        }

        return $this->landlords->findByIdentifier($value);
    }
}
