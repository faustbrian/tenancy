<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Console;

use Cline\Tenancy\Contracts\TenancyInterface;
use Cline\Tenancy\Contracts\TenantInterface;
use Cline\Tenancy\Contracts\TenantRepositoryInterface;
use Cline\Tenancy\TenantContext;
use Illuminate\Console\Command;

use function is_int;
use function is_string;

/**
 * Artisan command to run database seeders inside a tenant context.
 *
 * Executes the standard `db:seed` command within the tenant's isolated
 * database connection. Use `--tenant` to target a single tenant by id or
 * slug, or `--all` to run seeders for every registered tenant sequentially.
 * Stops and returns a failure exit code if any individual tenant seed fails.
 *
 * ```bash
 * # Seed a single tenant
 * php artisan tenancy:seed --tenant=acme
 *
 * # Seed all tenants with a specific seeder class
 * php artisan tenancy:seed --all --class=OrderSeeder --force
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class SeedCommand extends Command
{
    protected $signature = 'tenancy:seed
                            {--tenant= : TenantInterface id or slug}
                            {--all : Run seeder for all tenants}
                            {--class= : Seeder class name}
                            {--force : Force operation in production}';

    protected $description = 'Run database seeders in tenant context';

    /**
     * Create a new command instance.
     *
     * @param TenantRepositoryInterface $tenants Repository used to retrieve all tenants when `--all` is passed.
     * @param TenancyInterface          $tenancy Tenancy service used to resolve tenants and run callbacks in tenant context.
     */
    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenancyInterface $tenancy,
    ) {
        parent::__construct();
    }

    /**
     * Execute the command.
     *
     * Resolves the target tenant(s) and delegates to `runSeedForTenant()`
     * for each one. Returns early with `Command::FAILURE` if any seed call
     * fails. Requires either `--tenant` or `--all`; returns `Command::INVALID`
     * when neither is provided.
     *
     * @return int `Command::SUCCESS` when all seeders pass, `Command::FAILURE`
     *             when a seeder exits with a non-zero code, or `Command::INVALID`
     *             when neither `--tenant` nor `--all` is specified.
     */
    public function handle(): int
    {
        $singleTenant = $this->option('tenant');
        $all = (bool) $this->option('all');

        if (is_string($singleTenant) && $singleTenant !== '') {
            $tenant = $this->tenancy->tenant($singleTenant);

            if (!$tenant instanceof TenantContext) {
                $this->error('Unable to resolve tenant.');

                return self::FAILURE;
            }

            return $this->runSeedForTenant($tenant->tenant);
        }

        if (!$all) {
            $this->warn('No tenant selected. Use --tenant=<id|slug> or --all.');

            return self::INVALID;
        }

        foreach ($this->tenants->all() as $tenant) {
            $result = $this->runSeedForTenant($tenant);

            if ($result !== self::SUCCESS) {
                return $result;
            }
        }

        return self::SUCCESS;
    }

    /**
     * Run the `db:seed` Artisan command inside the given tenant's context.
     *
     * Switches the active tenant via `TenancyInterface::runAsTenant()` so the
     * seeder targets the tenant's database connection. The `--database` option
     * is appended when the tenancy configuration provides an explicit connection
     * name. An optional `--class` option forwards a specific seeder class.
     *
     * @param  TenantInterface $tenant The tenant whose database should be seeded.
     * @return int             The exit code returned by the `db:seed` command, or
     *                         `Command::FAILURE` if `runAsTenant` returns a non-integer value.
     */
    private function runSeedForTenant(TenantInterface $tenant): int
    {
        $class = $this->option('class');
        $parameters = [
            '--force' => (bool) $this->option('force'),
        ];

        if (is_string($class) && $class !== '') {
            $parameters['--class'] = $class;
        }

        $result = $this->tenancy->runAsTenant($tenant, function () use ($parameters): int {
            $connection = $this->tenancy->tenantConnection();

            if (is_string($connection) && $connection !== '') {
                $parameters['--database'] = $connection;
            }

            return (int) $this->call('db:seed', $parameters);
        });

        return is_int($result) ? $result : self::FAILURE;
    }
}
