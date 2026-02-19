<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Console;

use Illuminate\Console\Command;

use function config;
use function implode;
use function is_array;
use function is_string;

/**
 * Artisan command to verify the tenancy package configuration is correctly wired.
 *
 * Reads key values from the `tenancy` configuration file and performs a series
 * of sanity checks to confirm that all required settings are present and
 * non-empty. Each check is printed to the console as PASS or FAIL, and a
 * summary of failed checks is shown before exiting.
 *
 * ```bash
 * php artisan tenancy:test
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class TestCommand extends Command
{
    protected $signature = 'tenancy:test';

    protected $description = 'Run tenancy sanity checks for config and wiring';

    /**
     * Execute the command.
     *
     * Runs all configuration sanity checks and prints a PASS/FAIL line for
     * each one. When every check passes, exits with `Command::SUCCESS`. When
     * one or more checks fail, prints a summary of failed labels and exits
     * with `Command::FAILURE`.
     *
     * @return int `Command::SUCCESS` when all checks pass, or `Command::FAILURE`
     *             when one or more configuration values are missing or invalid.
     */
    public function handle(): int
    {
        $model = config('tenancy.tenant_model');
        $landlordModel = config('tenancy.landlord_model');
        $resolverConfig = config('tenancy.resolver.resolvers', []);
        $landlordResolverConfig = config('tenancy.landlord.resolver.resolvers', []);
        $isolation = config('tenancy.isolation');
        $routeParameter = config('tenancy.routing.tenant_parameter', 'tenant');
        $landlordRouteParameter = config('tenancy.routing.landlord_parameter', 'landlord');

        $checks = [
            'tenant model configured' => is_string($model) && $model !== '',
            'landlord model configured' => is_string($landlordModel) && $landlordModel !== '',
            'resolver list configured' => is_array($resolverConfig) && $resolverConfig !== [],
            'landlord resolver list configured' => is_array($landlordResolverConfig) && $landlordResolverConfig !== [],
            'isolation configured' => is_string($isolation) && $isolation !== '',
            'route parameter configured' => is_string($routeParameter) && $routeParameter !== '',
            'landlord route parameter configured' => is_string($landlordRouteParameter) && $landlordRouteParameter !== '',
        ];

        $failed = [];

        foreach ($checks as $label => $ok) {
            if ($ok) {
                $this->info('PASS: '.$label);

                continue;
            }

            $failed[] = $label;
            $this->error('FAIL: '.$label);
        }

        if ($failed === []) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Failed checks: '.implode(', ', $failed));

        return self::FAILURE;
    }
}
