<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Exceptions;

use RuntimeException;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class LandlordNotFailFastException extends RuntimeException
{
    public static function make(): self
    {
        return new self('landlord-not-fail-fast');
    }
}
