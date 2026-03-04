<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Contracts;

/**
 * Core contract that every tenant model must satisfy.
 *
 * A tenant represents an isolated customer or organisation within the
 * multi-tenant system. This interface defines the minimum surface required by
 * the tenancy layer to resolve, switch, and identify tenant contexts. Models
 * that need domain-based resolution or landlord association should additionally
 * implement the relevant optional contracts in this namespace.
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface TenantInterface
{
    /**
     * Return the primary key of this tenant.
     *
     * @return int|string The tenant's unique identifier.
     */
    public function id(): int|string;

    /**
     * Return the URL-safe slug that uniquely identifies this tenant.
     *
     * Used by resolvers and Artisan commands to look up a tenant by a
     * human-readable handle rather than a numeric id.
     *
     * @return string Non-empty slug string.
     */
    public function slug(): string;

    /**
     * Return the human-readable display name of this tenant.
     *
     * @return string Non-empty display name.
     */
    public function name(): string;

    /**
     * Return the list of domain names associated with this tenant.
     *
     * Domain-based resolvers use this list to match an incoming request
     * hostname against registered tenant domains. Each entry should be a
     * fully-qualified hostname (e.g. `"tenant.example.com"`) or a bare
     * subdomain label used for subdomain-based resolution.
     *
     * @return array<int, string> Ordered list of domain names for this tenant.
     */
    public function domains(): array;

    /**
     * Return the serialisable payload representing this tenant's context.
     *
     * The array is stored alongside queued jobs and other asynchronous
     * payloads so that the correct tenant context can be restored when the
     * work is processed. Keys and structure are implementation-defined but
     * must be sufficient to reconstruct the tenant via
     * `TenancyInterface::fromTenantPayload()`.
     *
     * @return array<string, mixed> Serialisable context payload for this tenant.
     */
    public function getContextPayload(): array;
}
