<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
use Cline\CodingStandard\Rector\Factory;
use Rector\CodingStyle\Rector\ClassLike\NewlineBetweenClassLikeStmtsRector;
use Rector\DeadCode\Rector\Stmt\RemoveUnreachableStatementRector;
use RectorLaravel\Rector\Class_\AddHasFactoryToModelsRector;
use RectorLaravel\Rector\MethodCall\ContainerBindConcreteWithClosureOnlyRector;

return Factory::create(
    paths: [__DIR__.'/src', __DIR__.'/tests'],
    skip: [
        NewlineBetweenClassLikeStmtsRector::class,
        RemoveUnreachableStatementRector::class => [__DIR__.'/tests'],
        AddHasFactoryToModelsRector::class,
        ContainerBindConcreteWithClosureOnlyRector::class,
    ],
);
