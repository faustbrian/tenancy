<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Exceptions;

use InvalidArgumentException;

/**
 * Base exception for invalid values found in the tenancy configuration file.
 *
 * All concrete subclasses are thrown during service-provider boot when the
 * package validates the `config/tenancy.php` values. Catching this base class
 * allows application code to handle any configuration-related failure in a
 * single place.
 *
 * @author Brian Faust <brian@cline.sh>
 * @see InvalidTenancyIsolationModeException
 * @see InvalidTenancyLandlordModelException
 * @see InvalidTenancyPrimaryKeyTypeException
 * @see InvalidTenancyResolverException
 * @see InvalidTenancyTableNameException
 * @see InvalidTenancyTaskException
 */
abstract class InvalidTenancyConfiguration extends InvalidArgumentException implements TenancyExceptionInterface {}
