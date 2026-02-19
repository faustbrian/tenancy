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
 * Artisan command to run database migrations inside a tenant context.
 *
 * Executes the standard `migrate` command within the tenant's isolated
 * database connection. Use `--tenant` to target a single tenant by id or
 * slug, or `--all` to run migrations for every registered tenant sequentially.
 * Stops and returns a failure exit code if any individual tenant migration
 * fails.
 *
 * ```bash
 * # Migrate a single tenant
 * php artisan tenancy:migrate --tenant=acme
 *
 * # Migrate all tenants
 * php artisan tenancy:migrate --all --force
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class MigrateCommand extends Command
{
    protected $signature = 'tenancy:migrate
                            {--tenant= : TenantInterface id or slug}
                            {--all : Run migration for all tenants}
                            {--force : Force operation in production}';

    protected $description = 'Run migrations in tenant context';

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
     * Resolves the target tenant(s) and delegates to `runMigrateForTenant()`
     * for each one. Returns early with `Command::FAILURE` if any migration
     * call fails. Requires either `--tenant` or `--all`; returns
     * `Command::INVALID` when neither is provided.
     *
     * @return int `Command::SUCCESS` when all migrations pass, `Command::FAILURE`
     *             when a migration exits with a non-zero code, or `Command::INVALID`
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

            return $this->runMigrateForTenant($tenant->tenant);
        }

        if (!$all) {
            $this->warn('No tenant selected. Use --tenant=<id|slug> or --all.');

            return self::INVALID;
        }

        foreach ($this->tenants->all() as $tenant) {
            $result = $this->runMigrateForTenant($tenant);

            if ($result !== self::SUCCESS) {
                return $result;
            }
        }

        return self::SUCCESS;
    }

    /**
     * Run the `migrate` Artisan command inside the given tenant's context.
     *
     * Switches the active tenant via `TenancyInterface::runAsTenant()` so the
     * migration targets the tenant's database connection. The `--database`
     * option is appended when the tenancy configuration provides an explicit
     * connection name.
     *
     * @param  TenantInterface $tenant The tenant whose database should be migrated.
     * @return int             The exit code returned by the `migrate` command, or
     *                         `Command::FAILURE` if `runAsTenant` returns a non-integer value.
     */
    private function runMigrateForTenant(TenantInterface $tenant): int
    {
        $result = $this->tenancy->runAsTenant($tenant, function (): int {
            $parameters = [
                '--force' => (bool) $this->option('force'),
            ];

            $connection = $this->tenancy->tenantConnection();

            if (is_string($connection) && $connection !== '') {
                $parameters['--database'] = $connection;
            }

            return (int) $this->call('migrate', $parameters);
        });

        return is_int($result) ? $result : self::FAILURE;
    }
}
