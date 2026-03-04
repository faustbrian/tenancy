<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Support;

use const PHP_URL_HOST;

use function is_string;
use function mb_strtolower;
use function mb_trim;
use function parse_url;
use function str_contains;

/**
 * Normalizes domain names and URLs to a consistent lowercase hostname format.
 *
 * Used internally by subdomain resolvers to ensure consistent comparisons
 * between request hosts and configured central domains, regardless of case,
 * surrounding whitespace, or the presence of a URL scheme.
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final class DomainNormalizer
{
    /**
     * Normalize a hostname or URL to a lowercase, scheme-free host string.
     *
     * When the value contains `://`, it is treated as a full URL and the host
     * component is extracted via `parse_url`. The result is lowercased and stripped
     * of surrounding whitespace and leading/trailing dots. Returns null when the
     * input is blank, the URL host cannot be parsed, or the normalized value is
     * empty after trimming.
     *
     * @param  string      $value A hostname (e.g., `Example.COM`) or full URL (e.g., `https://Example.COM/path`).
     * @return null|string The normalized hostname, or null if normalization fails.
     */
    public static function normalize(string $value): ?string
    {
        $host = mb_trim($value);

        if ($host === '') {
            return null;
        }

        if (str_contains($host, '://')) {
            $parsedHost = parse_url($host, PHP_URL_HOST);

            if (!is_string($parsedHost) || $parsedHost === '') {
                return null;
            }

            $host = $parsedHost;
        }

        $host = mb_trim(mb_strtolower($host), '.');

        return $host === '' ? null : $host;
    }
}
