<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Console;

use Cline\Tenancy\Contracts\TenantRepositoryInterface;
use Illuminate\Console\Command;

use function implode;

/**
 * Artisan command to list all registered tenants in a table.
 *
 * Retrieves every tenant from the repository and renders them in a
 * four-column table: ID, Slug, Name, and Domains.
 *
 * ```bash
 * php artisan tenancy:list
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class ListTenantsCommand extends Command
{
    protected $signature = 'tenancy:list';

    protected $description = 'List all tenants';

    /**
     * Create a new command instance.
     *
     * @param TenantRepositoryInterface $tenants Repository used to retrieve all tenants.
     */
    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
    ) {
        parent::__construct();
    }

    /**
     * Execute the command.
     *
     * Fetches all tenants and renders them as a formatted table. Domain values
     * are joined with a comma separator.
     *
     * @return int `Command::SUCCESS` always.
     */
    public function handle(): int
    {
        $rows = [];

        foreach ($this->tenants->all() as $tenant) {
            $rows[] = [
                (string) $tenant->id(),
                $tenant->slug(),
                $tenant->name(),
                implode(', ', $tenant->domains()),
            ];
        }

        $this->table(['ID', 'Slug', 'Name', 'Domains'], $rows);

        return self::SUCCESS;
    }
}
