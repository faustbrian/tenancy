<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Exceptions;

use Facade\IgnitionContracts\BaseSolution;
use Facade\IgnitionContracts\ProvidesSolution;
use Facade\IgnitionContracts\Solution;
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
abstract class InvalidTenancyConfiguration extends InvalidArgumentException implements ProvidesSolution, TenancyExceptionInterface
{
    public function getSolution(): Solution
    {
        /** @var BaseSolution $solution */
        $solution = BaseSolution::create('Review package usage and configuration.');

        return $solution
            ->setSolutionDescription('Exception: '.$this->getMessage())
            ->setDocumentationLinks([
                'Package documentation' => 'https://github.com/cline/tenancy',
            ]);
    }
}
