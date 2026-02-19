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
 * Artisan command to run database seeders inside a landlord context.
 *
 * Executes the standard `db:seed` command within the landlord's isolated
 * database connection. Use `--landlord` to target a single landlord by id or
 * slug, or `--all` to run seeders for every registered landlord sequentially.
 * Stops and returns a failure exit code if any individual landlord seed fails.
 *
 * ```bash
 * # Seed a single landlord
 * php artisan tenancy:seed-landlord --landlord=global
 *
 * # Seed all landlords with a specific seeder class
 * php artisan tenancy:seed-landlord --all --class=PlanSeeder --force
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class SeedLandlordCommand extends Command
{
    protected $signature = 'tenancy:seed-landlord
                            {--landlord= : LandlordInterface id or slug}
                            {--all : Run seeder for all landlords}
                            {--class= : Seeder class name}
                            {--force : Force operation in production}';

    protected $description = 'Run database seeders in landlord context';

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
     * Resolves the target landlord(s) and delegates to `runSeedForLandlord()`
     * for each one. Returns early with `Command::FAILURE` if any seed call
     * fails. Requires either `--landlord` or `--all`; returns `Command::INVALID`
     * when neither is provided.
     *
     * @return int `Command::SUCCESS` when all seeders pass, `Command::FAILURE`
     *             when a seeder exits with a non-zero code, or `Command::INVALID`
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

            return $this->runSeedForLandlord($landlord->landlord);
        }

        if (!$all) {
            $this->warn('No landlord selected. Use --landlord=<id|slug> or --all.');

            return self::INVALID;
        }

        foreach ($this->landlords->all() as $landlord) {
            $result = $this->runSeedForLandlord($landlord);

            if ($result !== self::SUCCESS) {
                return $result;
            }
        }

        return self::SUCCESS;
    }

    /**
     * Run the `db:seed` Artisan command inside the given landlord's context.
     *
     * Switches the active landlord via `TenancyInterface::runAsLandlord()` so
     * the seeder targets the landlord's database connection. The `--database`
     * option is appended when the tenancy configuration provides an explicit
     * connection name. An optional `--class` option forwards a specific seeder
     * class.
     *
     * @param  LandlordInterface $landlord The landlord whose database should be seeded.
     * @return int               The exit code returned by the `db:seed` command, or
     *                           `Command::FAILURE` if `runAsLandlord` returns a non-integer value.
     */
    private function runSeedForLandlord(LandlordInterface $landlord): int
    {
        $class = $this->option('class');
        $parameters = [
            '--force' => (bool) $this->option('force'),
        ];

        if (is_string($class) && $class !== '') {
            $parameters['--class'] = $class;
        }

        $result = $this->tenancy->runAsLandlord($landlord, function () use ($parameters): int {
            $connection = $this->tenancy->landlordConnection();

            if (is_string($connection) && $connection !== '') {
                $parameters['--database'] = $connection;
            }

            return (int) $this->call('db:seed', $parameters);
        });

        return is_int($result) ? $result : self::FAILURE;
    }
}
