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
use function is_int;
use function is_numeric;
use function is_string;

/**
 * Resolves the active landlord from a URL path segment.
 *
 * Reads the 1-based segment position from `tenancy.landlord.resolver.path_segment`
 * (default: `1`) and treats that segment's value as the landlord identifier. Falls
 * back to segment 1 when the configured value is not a valid integer or numeric
 * string. Returns null when the segment is absent or empty, or when no matching
 * landlord exists in the repository.
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class PathLandlordResolver implements LandlordResolverInterface
{
    /**
     * Create a new path-based landlord resolver.
     *
     * @param LandlordRepositoryInterface $landlords Repository used to look up landlords by identifier.
     */
    public function __construct(
        private LandlordRepositoryInterface $landlords,
    ) {}

    /**
     * Resolve the landlord from the specified URL path segment.
     *
     * The segment position is read from the `tenancy.landlord.resolver.path_segment`
     * config key and defaults to `1`. Invalid config values (non-integer, non-numeric)
     * are silently reset to `1`. Returns null when the segment is missing or empty,
     * or when no landlord matches the extracted identifier.
     *
     * @param  Request                $request The incoming HTTP request.
     * @return null|LandlordInterface The resolved landlord, or null if resolution fails.
     */
    public function resolve(Request $request): ?LandlordInterface
    {
        $segment = config('tenancy.landlord.resolver.path_segment', 1);

        if (!is_int($segment) && (!is_string($segment) || !is_numeric($segment))) {
            $segment = 1;
        }

        $identifier = $request->segment((int) $segment);

        if (!is_string($identifier) || $identifier === '') {
            return null;
        }

        return $this->landlords->findByIdentifier($identifier);
    }
}
