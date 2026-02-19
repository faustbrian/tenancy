<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Console;

use Cline\Tenancy\Contracts\DomainAwareLandlordInterface;
use Cline\Tenancy\Contracts\LandlordRepositoryInterface;
use Illuminate\Console\Command;

use function implode;

/**
 * Artisan command to list all registered landlords in a table.
 *
 * Retrieves every landlord from the repository and renders them in a
 * four-column table: ID, Slug, Name, and Domains. The Domains column is
 * only populated when the landlord implements `DomainAwareLandlordInterface`.
 *
 * ```bash
 * php artisan tenancy:list-landlords
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class ListLandlordsCommand extends Command
{
    protected $signature = 'tenancy:list-landlords';

    protected $description = 'List all landlords';

    /**
     * Create a new command instance.
     *
     * @param LandlordRepositoryInterface $landlords Repository used to retrieve all landlords.
     */
    public function __construct(
        private readonly LandlordRepositoryInterface $landlords,
    ) {
        parent::__construct();
    }

    /**
     * Execute the command.
     *
     * Fetches all landlords and renders them as a formatted table. Domains are
     * joined with a comma separator and left empty for landlords that do not
     * implement domain awareness.
     *
     * @return int `Command::SUCCESS` always.
     */
    public function handle(): int
    {
        $rows = [];

        foreach ($this->landlords->all() as $landlord) {
            $domains = [];

            if ($landlord instanceof DomainAwareLandlordInterface) {
                $domains = $landlord->domains();
            }

            $rows[] = [
                (string) $landlord->id(),
                $landlord->slug(),
                $landlord->name(),
                implode(', ', $domains),
            ];
        }

        $this->table(['ID', 'Slug', 'Name', 'Domains'], $rows);

        return self::SUCCESS;
    }
}
