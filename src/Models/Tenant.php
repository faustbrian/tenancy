<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Models;

use Cline\Tenancy\Contracts\DatabaseAwareTenantInterface;
use Cline\Tenancy\Contracts\FeatureAwareTenantInterface;
use Cline\Tenancy\Contracts\LandlordAwareTenantInterface;
use Cline\Tenancy\Contracts\TenantInterface;
use Cline\VariableKeys\Database\Concerns\HasVariablePrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use function array_filter;
use function array_values;
use function config;
use function is_array;
use function is_int;
use function is_string;

/**
 * Default Eloquent tenant model for the multi-tenancy package.
 *
 * Represents an isolated customer or organisation (tenant) within the system.
 * Implements all four core tenant contracts — base identity, landlord association,
 * feature flags, and database configuration — making it suitable for most
 * hierarchical multi-tenant deployments out of the box.
 *
 * The primary key type is variable and controlled by {@see HasVariablePrimaryKey},
 * allowing both integer and string (e.g. UUID) primary keys without additional
 * model configuration.
 *
 * The `domains`, `features`, and `database` columns are automatically cast to
 * arrays, so they may be stored as JSON in the underlying database table.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class Tenant extends Model implements DatabaseAwareTenantInterface, FeatureAwareTenantInterface, LandlordAwareTenantInterface, TenantInterface
{
    use HasVariablePrimaryKey;

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'domains' => 'array',
        'features' => 'array',
        'database' => 'array',
    ];

    /**
     * Return the tenant's primary key value.
     *
     * Returns an empty string when the underlying key is neither an integer
     * nor a string, which can occur on unsaved model instances.
     *
     * @return int|string The tenant's primary key.
     */
    public function id(): int|string
    {
        $key = $this->getKey();

        return is_int($key) || is_string($key) ? $key : '';
    }

    /**
     * Return the URL-safe slug that uniquely identifies this tenant.
     *
     * Falls back to an empty string when the `slug` attribute is absent or
     * not a string, satisfying the {@see TenantInterface} contract without
     * throwing on unset models.
     *
     * @return string The tenant's slug, or an empty string if not set.
     */
    public function slug(): string
    {
        $slug = $this->getAttribute('slug');

        return is_string($slug) ? $slug : '';
    }

    /**
     * Return the human-readable display name of this tenant.
     *
     * Falls back to an empty string when the `name` attribute is absent or
     * not a string.
     *
     * @return string The tenant's display name, or an empty string if not set.
     */
    public function name(): string
    {
        $name = $this->getAttribute('name');

        return is_string($name) ? $name : '';
    }

    /**
     * Return the list of domain names associated with this tenant.
     *
     * The `domains` column is cast from JSON to an array. Non-string entries
     * are filtered out and the result is re-indexed, ensuring a clean
     * `array<int, string>` regardless of what was stored.
     *
     * @return array<int, string> Indexed list of domain names for this tenant.
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
     * Return the feature flags and their configuration values for this tenant.
     *
     * The `features` column is cast from JSON to an associative array. Keys
     * are feature identifiers and values are implementation-defined — a boolean
     * for simple on/off flags or a nested array for parameterised features.
     * Returns an empty array when the attribute is absent or not an array.
     *
     * @return array<string, mixed> Feature flag map for this tenant.
     */
    public function features(): array
    {
        $features = $this->getAttribute('features');

        if (!is_array($features)) {
            return [];
        }

        /** @var array<string, mixed> $features */
        return $features;
    }

    /**
     * Return the tenant-specific database connection configuration.
     *
     * The `database` column is cast from JSON to an associative array whose
     * shape mirrors a Laravel database connection entry (driver, host, port,
     * database, username, password, etc.). Returns `null` when the tenant uses
     * the application's default connection rather than a dedicated one.
     *
     * @return null|array<string, mixed> Connection configuration, or null if not configured.
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

    /**
     * Return the serialisable payload representing this tenant's context.
     *
     * The payload is embedded in queued jobs and other asynchronous workloads
     * so the tenancy layer can restore the correct tenant context when the job
     * is processed. The landlord identifier is included under a configurable
     * key (`tenancy.landlord.payload_key`, defaulting to `landlord_id`).
     *
     * @return array<string, mixed> Serialisable context payload for this tenant.
     */
    public function getContextPayload(): array
    {
        $payloadKey = config('tenancy.landlord.payload_key', 'landlord_id');

        if (!is_string($payloadKey) || $payloadKey === '') {
            $payloadKey = 'landlord_id';
        }

        return [
            'name' => $this->name(),
            'domains' => $this->domains(),
            $payloadKey => $this->landlordId(),
        ];
    }

    /**
     * Return the primary key of the landlord that owns this tenant.
     *
     * Returns `null` when the tenant is not associated with a landlord, for
     * example in a flat (non-hierarchical) multi-tenant setup.
     *
     * @return null|int|string The owning landlord's primary key, or null if unset.
     */
    public function landlordId(): int|string|null
    {
        $landlordId = $this->getAttribute('landlord_id');

        return is_int($landlordId) || is_string($landlordId) ? $landlordId : null;
    }

    /**
     * Return the landlord that owns this tenant.
     *
     * @return BelongsTo<Landlord, $this>
     */
    public function landlord(): BelongsTo
    {
        return $this->belongsTo(Landlord::class, 'landlord_id');
    }
}
