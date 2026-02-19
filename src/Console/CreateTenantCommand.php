<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Console;

use Cline\Tenancy\Contracts\TenancyInterface;
use Cline\Tenancy\Contracts\TenantRepositoryInterface;
use Cline\Tenancy\LandlordContext;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function array_filter;
use function array_values;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Artisan command to create a new tenant record.
 *
 * Persists a tenant with the given slug, display name, and optional domain
 * values via `TenantRepositoryInterface`. When `--landlord` is provided, the
 * command resolves the landlord by id or slug and associates the new tenant
 * with it. The slug is automatically normalized using `Str::slug()`.
 *
 * ```bash
 * php artisan tenancy:create acme "Acme Corp" --domain=acme.com --landlord=global
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class CreateTenantCommand extends Command
{
    protected $signature = 'tenancy:create
                            {slug : Tenant slug}
                            {name : Tenant display name}
                            {--domain=* : Tenant domain values}
                            {--landlord= : Landlord id or slug}';

    protected $description = 'Create a new tenant';

    /**
     * Create a new command instance.
     *
     * @param TenantRepositoryInterface $tenants Repository used to persist the new tenant.
     * @param TenancyInterface          $tenancy Tenancy service used to resolve the optional landlord.
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
     * Validates that `slug` and `name` are non-empty strings, sanitizes any
     * `--domain` values, and optionally resolves the `--landlord` argument to
     * a `LandlordContext` before delegating to the tenant repository. Outputs
     * the new tenant's slug and id on success.
     *
     * @return int `Command::SUCCESS` on success, `Command::INVALID` when
     *             required arguments are missing, or `Command::FAILURE` when
     *             the specified landlord cannot be resolved.
     */
    public function handle(): int
    {
        $slug = $this->argument('slug');
        $name = $this->argument('name');
        $domains = $this->option('domain');
        $landlordIdentifier = $this->option('landlord');

        if (!is_string($slug) || $slug === '' || !is_string($name) || $name === '') {
            $this->error('Both slug and name are required strings.');

            return self::INVALID;
        }

        $domainValues = is_array($domains)
            ? array_values(array_filter($domains, static fn (mixed $value): bool => is_string($value) && $value !== ''))
            : [];

        $landlordId = null;

        if (is_string($landlordIdentifier) && $landlordIdentifier !== '') {
            $landlord = $this->tenancy->landlord($landlordIdentifier);

            if (!$landlord instanceof LandlordContext) {
                $this->error('Unable to resolve landlord.');

                return self::FAILURE;
            }

            $landlordId = $landlord->id();
        }

        $tenant = $this->tenants->create([
            'slug' => Str::slug($slug),
            'name' => $name,
            'domains' => $domainValues,
            'landlord_id' => $landlordId,
        ]);

        $this->info(sprintf('Created tenant [%s] (%s).', $tenant->slug(), (string) $tenant->id()));

        return self::SUCCESS;
    }
}
