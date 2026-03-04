<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when tenancy middleware receives a response that is not a Symfony `Response` instance.
 *
 * The tenancy middleware pipeline expects every handler to return a
 * `\Symfony\Component\HttpFoundation\Response` so that context clean-up tasks
 * can run reliably. This exception is raised when the pipeline receives an
 * incompatible return value.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class UnexpectedMiddlewareResponseException extends RuntimeException implements TenancyExceptionInterface
{
    /**
     * Create an exception indicating a Symfony response instance was expected.
     */
    public static function expectedSymfonyResponse(): self
    {
        return new self('Expected a Symfony response instance.');
    }
}
