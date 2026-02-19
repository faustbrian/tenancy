<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Fixtures;

use Cline\Tenancy\Contracts\TaskInterface;
use Cline\Tenancy\TenantContext;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class FakeTask implements TaskInterface
{
    /** @var array<int, int|string> */
    public static array $made = [];

    /** @var array<int, int|string> */
    public static array $forgotten = [];

    public static function reset(): void
    {
        self::$made = [];
        self::$forgotten = [];
    }

    public function makeCurrent(TenantContext $tenantContext): void
    {
        self::$made[] = $tenantContext->id();
    }

    public function forgetCurrent(TenantContext $tenantContext): void
    {
        self::$forgotten[] = $tenantContext->id();
    }
}
