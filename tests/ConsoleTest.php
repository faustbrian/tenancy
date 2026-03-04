<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Tenancy\Models\Landlord;
use Cline\Tenancy\Models\Tenant;
use Tests\Fixtures\TenantSeeder;

it('creates and lists tenants via artisan commands', function (): void {
    $this->artisan('tenancy:create', [
        'slug' => 'acme-co',
        'name' => 'Acme Co',
        '--domain' => ['acme.example.test'],
    ])->assertSuccessful();

    $this->artisan('tenancy:list')
        ->expectsTable(['ID', 'Slug', 'Name', 'Domains'], [
            ['1', 'acme-co', 'Acme Co', 'acme.example.test'],
        ])
        ->assertSuccessful();
});

it('creates and lists landlords via artisan commands', function (): void {
    $this->artisan('tenancy:create-landlord', [
        'slug' => 'division-east',
        'name' => 'Division East',
        '--domain' => ['division-east.example.test'],
    ])->assertSuccessful();

    $this->artisan('tenancy:list-landlords')
        ->expectsTable(['ID', 'Slug', 'Name', 'Domains'], [
            ['1', 'division-east', 'Division East', 'division-east.example.test'],
        ])
        ->assertSuccessful();
});

it('creates tenant with landlord via artisan command', function (): void {
    $landlord = Landlord::query()->create([
        'slug' => 'division-west',
        'name' => 'Division West',
    ]);

    $this->artisan('tenancy:create', [
        'slug' => 'tenant-west',
        'name' => 'Tenant West',
        '--landlord' => 'division-west',
    ])->assertSuccessful();

    $tenant = Tenant::query()->where('slug', 'tenant-west')->first();

    expect($tenant)->not->toBeNull()
        ->and($tenant?->getAttribute('landlord_id'))->toBe($landlord->id);
});

it('runs sanity checks command', function (): void {
    $this->artisan('tenancy:test')->assertSuccessful();
});

it('runs tenant scoped command wrappers', function (): void {
    Tenant::query()->create([
        'slug' => 'tenant-a',
        'name' => 'Tenant A',
        'domains' => ['tenant-a.example.test'],
    ]);

    $this->artisan('tenancy:migrate', ['--tenant' => 'tenant-a'])->assertSuccessful();
    $this->artisan('tenancy:seed', [
        '--tenant' => 'tenant-a',
        '--class' => TenantSeeder::class,
    ])->assertSuccessful();
    $this->artisan('tenancy:run', [
        'tenant' => 'tenant-a',
        'artisan' => 'list',
    ])->assertSuccessful();
});

it('runs landlord scoped command wrappers', function (): void {
    Landlord::query()->create([
        'slug' => 'division-a',
        'name' => 'Division A',
        'domains' => ['division-a.example.test'],
    ]);

    $this->artisan('tenancy:migrate-landlord', ['--landlord' => 'division-a'])->assertSuccessful();
    $this->artisan('tenancy:seed-landlord', [
        '--landlord' => 'division-a',
        '--class' => TenantSeeder::class,
    ])->assertSuccessful();
    $this->artisan('tenancy:run-landlord', [
        'landlord' => 'division-a',
        'artisan' => 'list',
    ])->assertSuccessful();
});
