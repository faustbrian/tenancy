<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Exceptions;

use RuntimeException;

use function sprintf;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class NotImplementedTestStubException extends RuntimeException
{
    public static function forMethod(string $method): self
    {
        return new self(sprintf('Method %s is not implemented for this test stub.', $method));
    }
}
