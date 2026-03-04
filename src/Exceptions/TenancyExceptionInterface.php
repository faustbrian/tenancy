<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Exceptions;

use Throwable;

/**
 * Marker interface implemented by every exception thrown by the Tenancy package.
 *
 * Catching this interface in application code provides a single interception
 * point for all tenancy-related failures, regardless of the concrete exception
 * class. No methods are declared; the interface exists solely for type-level
 * grouping and exception handling.
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface TenancyExceptionInterface extends Throwable {}
