<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Console;

use Cline\Tenancy\Contracts\LandlordRepositoryInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function array_filter;
use function array_values;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Artisan command to create a new landlord record.
 *
 * Persists a landlord with the given slug, display name, and optional domain
 * values via `LandlordRepositoryInterface`. The slug is automatically
 * normalized using `Str::slug()` before being stored.
 *
 * ```bash
 * php artisan tenancy:create-landlord acme "Acme Corp" --domain=acme.com --domain=www.acme.com
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class CreateLandlordCommand extends Command
{
    protected $signature = 'tenancy:create-landlord
                            {slug : Landlord slug}
                            {name : Landlord display name}
                            {--domain=* : Landlord domain values}';

    protected $description = 'Create a new landlord';

    /**
     * Create a new command instance.
     *
     * @param LandlordRepositoryInterface $landlords Repository used to persist the new landlord.
     */
    public function __construct(
        private readonly LandlordRepositoryInterface $landlords,
    ) {
        parent::__construct();
    }

    /**
     * Execute the command.
     *
     * Validates that `slug` and `name` are non-empty strings, sanitizes any
     * provided `--domain` values, and delegates to the landlord repository to
     * create and persist the record. Outputs the new landlord's slug and id on
     * success.
     *
     * @return int `Command::SUCCESS` on success, `Command::INVALID` when
     *             required arguments are missing or invalid.
     */
    public function handle(): int
    {
        $slug = $this->argument('slug');
        $name = $this->argument('name');
        $domains = $this->option('domain');

        if (!is_string($slug) || $slug === '' || !is_string($name) || $name === '') {
            $this->error('Both slug and name are required strings.');

            return self::INVALID;
        }

        $domainValues = is_array($domains)
            ? array_values(array_filter($domains, static fn (mixed $value): bool => is_string($value) && $value !== ''))
            : [];

        $landlord = $this->landlords->create([
            'slug' => Str::slug($slug),
            'name' => $name,
            'domains' => $domainValues,
        ]);

        $this->info(sprintf('Created landlord [%s] (%s).', $landlord->slug(), (string) $landlord->id()));

        return self::SUCCESS;
    }
}
