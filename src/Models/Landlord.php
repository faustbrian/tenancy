<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Models;

use Cline\Tenancy\Contracts\DatabaseAwareLandlordInterface;
use Cline\Tenancy\Contracts\DomainAwareLandlordInterface;
use Cline\Tenancy\Contracts\LandlordInterface;
use Cline\VariableKeys\Database\Concerns\HasVariablePrimaryKey;
use Illuminate\Database\Eloquent\Model;

use function array_filter;
use function array_values;
use function is_array;
use function is_int;
use function is_string;

/**
 * Default Eloquent model for a landlord entity.
 *
 * Implements `LandlordInterface`, `DomainAwareLandlordInterface`, and
 * `DatabaseAwareLandlordInterface` so that the bundled middleware, resolvers,
 * and context factories work out of the box with this model.
 *
 * The `database`, `domains`, and `payload` columns are cast to arrays, allowing
 * them to be stored as JSON in the underlying table. All mass-assignment
 * protection is disabled (`$guarded = []`) — access control should be enforced
 * at the application layer.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class Landlord extends Model implements DatabaseAwareLandlordInterface, DomainAwareLandlordInterface, LandlordInterface
{
    use HasVariablePrimaryKey;

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'database' => 'array',
        'domains' => 'array',
        'payload' => 'array',
    ];

    /**
     * Return the landlord's primary key as an int or string.
     *
     * Returns an empty string when the model has not yet been persisted or
     * when the key is of an unexpected type.
     */
    public function id(): int|string
    {
        $key = $this->getKey();

        return is_int($key) || is_string($key) ? $key : '';
    }

    /**
     * Return the landlord's URL-safe slug.
     *
     * Returns an empty string when the `slug` attribute is absent or not a string.
     */
    public function slug(): string
    {
        $slug = $this->getAttribute('slug');

        return is_string($slug) ? $slug : '';
    }

    /**
     * Return the landlord's display name.
     *
     * Returns an empty string when the `name` attribute is absent or not a string.
     */
    public function name(): string
    {
        $name = $this->getAttribute('name');

        return is_string($name) ? $name : '';
    }

    /**
     * Return the context payload for this landlord.
     *
     * Merges the landlord's `name` and `domains` with any additional key/value
     * pairs stored in the `payload` JSON column. Used when serialising the
     * landlord context alongside queued jobs or building `LandlordContext` instances.
     *
     * @return array<string, mixed>
     */
    public function getContextPayload(): array
    {
        $payload = $this->getAttribute('payload');
        $domains = $this->domains();

        if (!is_array($payload)) {
            return [
                'name' => $this->name(),
                'domains' => $domains,
            ];
        }

        /** @var array<string, mixed> $payload */
        return [
            'name' => $this->name(),
            'domains' => $domains,
        ] + $payload;
    }

    /**
     * Return the list of domains associated with this landlord.
     *
     * Filters the `domains` JSON column to ensure only string values are returned.
     * Returns an empty array when the column is absent, null, or contains no strings.
     *
     * @return array<int, string>
     */
    public function domains(): array
    {
        $domains = $this->getAttribute('domains');

        if (!is_array($domains)) {
            return [];
        }

        /** @var array<int, string> $domains */
        return array_values(array_filter($domains, is_string(...)));
    }

    /**
     * Return the database connection configuration for this landlord.
     *
     * Reads from the `database` JSON column, which may contain driver-specific
     * connection parameters (e.g. `host`, `port`, `database`, `username`).
     * Returns `null` when no database configuration has been set.
     *
     * @return null|array<string, mixed>
     */
    public function databaseConfig(): ?array
    {
        $database = $this->getAttribute('database');

        if (!is_array($database)) {
            return null;
        }

        /** @var array<string, mixed> $database */
        return $database;
    }
}
