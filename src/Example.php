<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy;

/**
 * Illustrates how to consume the {@see Tenancy} service within application code.
 *
 * This class is provided as a reference implementation and is not intended for
 * production use. It demonstrates injecting the tenancy service and reading the
 * current tenant identifier from the active context.
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class Example
{
    /**
     * Create a new example instance.
     *
     * @param Tenancy $tenancy The tenancy service used to read the active tenant context.
     */
    public function __construct(
        private Tenancy $tenancy,
    ) {}

    /**
     * Return the current tenant identifier, or "system" when no tenant is active.
     *
     * @return string The active tenant ID cast to a string, or the literal string "system"
     *                when the application is running in the landlord/system context.
     */
    public function foo(): string
    {
        return (string) ($this->tenancy->tenantId() ?? 'system');
    }
}
