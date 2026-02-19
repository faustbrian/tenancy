<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Tenancy\Contracts\DomainAwareLandlordInterface;
use Cline\Tenancy\Contracts\TenancyInterface;
use Cline\Tenancy\Models\Landlord;
use Cline\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Route;

use function Cline\Tenancy\landlord;
use function Cline\Tenancy\landlord_action;
use function Cline\Tenancy\landlord_context;
use function Cline\Tenancy\landlord_route;
use function Cline\Tenancy\landlord_url;
use function Cline\Tenancy\tenant;
use function Cline\Tenancy\tenant_action;
use function Cline\Tenancy\tenant_route;
use function Cline\Tenancy\tenant_url;

it('builds tenant route and tenant url helpers', function (): void {
    Route::get('/t/{tenant}/dashboard', static fn (): string => 'ok')->name('tenant.dashboard');
    Route::get('/l/{landlord}/dashboard', static fn (): string => 'ok')->name('landlord.dashboard');

    $tenant = Tenant::query()->create([
        'slug' => 'acme',
        'name' => 'Acme',
        'domains' => ['acme.example.test'],
    ]);

    $route = tenant_route('tenant.dashboard', tenant: $tenant);
    $url = tenant_url($tenant, '/billing');

    expect($route)->toContain('/t/acme/dashboard')
        ->and($url)->toBe('http://acme.example.test/billing')
        ->and(tenant($tenant)?->slug())->toBe('acme');
});

it('builds landlord context helper', function (): void {
    Route::get('/l/{landlord}/dashboard', static fn (): string => 'ok')->name('landlord.dashboard');

    $landlord = Landlord::query()->create([
        'slug' => 'division-a',
        'name' => 'Division A',
        'domains' => ['division-a.example.test'],
    ]);

    expect(landlord_context($landlord)?->slug())->toBe('division-a')
        ->and(landlord($landlord)?->slug())->toBe('division-a');

    $route = landlord_route('landlord.dashboard', landlord: $landlord);
    $url = landlord_url($landlord, '/billing');

    expect($route)->toContain('/l/division-a/dashboard')
        ->and($url)->toBe('http://division-a.example.test/billing');

    resolve(TenancyInterface::class)->runAsLandlord($landlord, static function (): void {
        expect(landlord_context()?->slug())->toBe('division-a')
            ->and(landlord_route('landlord.dashboard'))->toContain('/l/division-a/dashboard');
    });
});

it('prefers domain aware landlord contract over payload domains in helper', function (): void {
    $landlord = new class() implements DomainAwareLandlordInterface
    {
        public function id(): string
        {
            return 'division-contract';
        }

        public function slug(): string
        {
            return 'division-contract';
        }

        public function name(): string
        {
            return 'Division Contract';
        }

        public function domains(): array
        {
            return ['division-contract.example.test'];
        }

        public function getContextPayload(): array
        {
            return ['name' => 'Division Contract'];
        }
    };

    $url = resolve(TenancyInterface::class)->runAsLandlord($landlord, static fn (): string => landlord_url(path: '/settings'));

    expect($url)->toBe('http://division-contract.example.test/settings');
});

it('runs tenant action in tenant or system context', function (): void {
    $tenant = Tenant::query()->create([
        'slug' => 'helper-tenant-action',
        'name' => 'Helper Tenant Action',
        'domains' => ['helper-tenant-action.example.test'],
    ]);

    $result = tenant_action(static fn (): ?string => resolve(TenancyInterface::class)->currentTenant()?->slug(), $tenant);
    $systemResult = tenant_action(static fn (): ?string => resolve(TenancyInterface::class)->currentTenant()?->slug());

    expect($result)->toBe('helper-tenant-action')
        ->and($systemResult)->toBeNull();
});

it('runs landlord action in landlord or system context', function (): void {
    $landlord = Landlord::query()->create([
        'slug' => 'helper-landlord-action',
        'name' => 'Helper Landlord Action',
        'domains' => ['helper-landlord-action.example.test'],
    ]);

    $result = landlord_action(static fn (): ?string => resolve(TenancyInterface::class)->currentLandlord()?->slug(), $landlord);
    $systemResult = landlord_action(static fn (): ?string => resolve(TenancyInterface::class)->currentLandlord()?->slug());

    expect($result)->toBe('helper-landlord-action')
        ->and($systemResult)->toBeNull();
});
