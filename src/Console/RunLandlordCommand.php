<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Console;

use Cline\Tenancy\Contracts\TenancyInterface;
use Cline\Tenancy\LandlordContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

use function is_int;
use function is_string;

/**
 * Artisan command to run an arbitrary Artisan command inside a landlord context.
 *
 * Resolves the specified landlord by id or slug, switches the active landlord
 * context via `TenancyInterface::runAsLandlord()`, and then calls the given
 * Artisan command string. Any output produced by the inner command is forwarded
 * to the console.
 *
 * ```bash
 * php artisan tenancy:run-landlord global "queue:work --once"
 * php artisan tenancy:run-landlord 1 "cache:clear"
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class RunLandlordCommand extends Command
{
    protected $signature = 'tenancy:run-landlord
                            {landlord : Landlord id or slug}
                            {artisan : Artisan command to run, e.g. "queue:work --once"}';

    protected $description = 'Run an Artisan command inside a landlord context';

    /**
     * Create a new command instance.
     *
     * @param TenancyInterface $tenancy Tenancy service used to resolve the landlord and execute the callback in landlord context.
     */
    public function __construct(
        private readonly TenancyInterface $tenancy,
    ) {
        parent::__construct();
    }

    /**
     * Execute the command.
     *
     * Validates both arguments, resolves the landlord, and runs the given
     * Artisan command string inside the landlord context. The inner command's
     * output is printed to the console after execution completes.
     *
     * @return int `Command::SUCCESS` or the exit code returned by the inner
     *             Artisan command, `Command::FAILURE` when the landlord cannot
     *             be resolved or the inner call returns a non-integer, or
     *             `Command::INVALID` when either argument is empty.
     */
    public function handle(): int
    {
        $landlordIdentifier = $this->argument('landlord');
        $command = $this->argument('artisan');

        if (!is_string($landlordIdentifier) || $landlordIdentifier === '' || !is_string($command) || $command === '') {
            $this->error('Both landlord and artisan arguments must be non-empty strings.');

            return self::INVALID;
        }

        $landlord = $this->tenancy->landlord($landlordIdentifier);

        if (!$landlord instanceof LandlordContext) {
            $this->error('Unable to resolve landlord.');

            return self::FAILURE;
        }

        $code = $this->tenancy->runAsLandlord($landlord, static fn (): int => (int) Artisan::call($command));

        $output = Artisan::output();

        if ($output !== '') {
            $this->line($output);
        }

        return is_int($code) ? $code : self::FAILURE;
    }
}
