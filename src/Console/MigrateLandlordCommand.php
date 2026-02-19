<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Console;

use Cline\Tenancy\Contracts\LandlordInterface;
use Cline\Tenancy\Contracts\LandlordRepositoryInterface;
use Cline\Tenancy\Contracts\TenancyInterface;
use Cline\Tenancy\LandlordContext;
use Illuminate\Console\Command;

use function is_int;
use function is_string;

/**
 * Artisan command to run database migrations inside a landlord context.
 *
 * Executes the standard `migrate` command within the landlord's isolated
 * database connection. Use `--landlord` to target a single landlord by id or
 * slug, or `--all` to run migrations for every registered landlord
 * sequentially. Stops and returns a failure exit code if any individual
 * landlord migration fails.
 *
 * ```bash
 * # Migrate a single landlord
 * php artisan tenancy:migrate-landlord --landlord=global
 *
 * # Migrate all landlords
 * php artisan tenancy:migrate-landlord --all --force
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class MigrateLandlordCommand extends Command
{
    protected $signature = 'tenancy:migrate-landlord
                            {--landlord= : LandlordInterface id or slug}
                            {--all : Run migration for all landlords}
                            {--force : Force operation in production}';

    protected $description = 'Run migrations in landlord context';

    /**
     * Create a new command instance.
     *
     * @param LandlordRepositoryInterface $landlords Repository used to retrieve all landlords when `--all` is passed.
     * @param TenancyInterface            $tenancy   Tenancy service used to resolve landlords and run callbacks in landlord context.
     */
    public function __construct(
        private readonly LandlordRepositoryInterface $landlords,
        private readonly TenancyInterface $tenancy,
    ) {
        parent::__construct();
    }

    /**
     * Execute the command.
     *
     * Resolves the target landlord(s) and delegates to
     * `runMigrateForLandlord()` for each one. Returns early with
     * `Command::FAILURE` if any migration call fails. Requires either
     * `--landlord` or `--all`; returns `Command::INVALID` when neither is
     * provided.
     *
     * @return int `Command::SUCCESS` when all migrations pass, `Command::FAILURE`
     *             when a migration exits with a non-zero code, or `Command::INVALID`
     *             when neither `--landlord` nor `--all` is specified.
     */
    public function handle(): int
    {
        $singleLandlord = $this->option('landlord');
        $all = (bool) $this->option('all');

        if (is_string($singleLandlord) && $singleLandlord !== '') {
            $landlord = $this->tenancy->landlord($singleLandlord);

            if (!$landlord instanceof LandlordContext) {
                $this->error('Unable to resolve landlord.');

                return self::FAILURE;
            }

            return $this->runMigrateForLandlord($landlord->landlord);
        }

        if (!$all) {
            $this->warn('No landlord selected. Use --landlord=<id|slug> or --all.');

            return self::INVALID;
        }

        foreach ($this->landlords->all() as $landlord) {
            $result = $this->runMigrateForLandlord($landlord);

            if ($result !== self::SUCCESS) {
                return $result;
            }
        }

        return self::SUCCESS;
    }

    /**
     * Run the `migrate` Artisan command inside the given landlord's context.
     *
     * Switches the active landlord via `TenancyInterface::runAsLandlord()` so
     * the migration targets the landlord's database connection. The
     * `--database` option is appended when the tenancy configuration provides
     * an explicit connection name.
     *
     * @param  LandlordInterface $landlord The landlord whose database should be migrated.
     * @return int               The exit code returned by the `migrate` command, or
     *                           `Command::FAILURE` if `runAsLandlord` returns a non-integer value.
     */
    private function runMigrateForLandlord(LandlordInterface $landlord): int
    {
        $result = $this->tenancy->runAsLandlord($landlord, function (): int {
            $parameters = [
                '--force' => (bool) $this->option('force'),
            ];

            $connection = $this->tenancy->landlordConnection();

            if (is_string($connection) && $connection !== '') {
                $parameters['--database'] = $connection;
            }

            return (int) $this->call('migrate', $parameters);
        });

        return is_int($result) ? $result : self::FAILURE;
    }
}
