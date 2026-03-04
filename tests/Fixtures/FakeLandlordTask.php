<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Fixtures;

use Cline\Tenancy\Contracts\LandlordTaskInterface;
use Cline\Tenancy\LandlordContext;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class FakeLandlordTask implements LandlordTaskInterface
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

    public function makeCurrent(LandlordContext $landlordContext): void
    {
        self::$made[] = $landlordContext->id();
    }

    public function forgetCurrent(LandlordContext $landlordContext): void
    {
        self::$forgotten[] = $landlordContext->id();
    }
}
