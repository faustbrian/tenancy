<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Tenancy\Models\Landlord;
use Cline\Tenancy\Models\Tenant;
use Cline\Tenancy\Resolvers\DomainLandlordResolver;
use Cline\Tenancy\Resolvers\DomainTenantResolver;
use Illuminate\Http\Request;

it('keeps tenant domain lookup table in sync when domains change', function (): void {
    $tenant = Tenant::query()->create([
        'slug' => 'sync-tenant',
        'name' => 'Sync Tenant',
        'domains' => ['sync-tenant.example.test'],
    ]);

    $resolver = resolve(DomainTenantResolver::class);

    expect($resolver->resolve(Request::create('https://sync-tenant.example.test/dashboard'))?->id())->toBe($tenant->id);

    $tenant->update([
        'domains' => ['sync-tenant-next.example.test'],
    ]);

    expect($resolver->resolve(Request::create('https://sync-tenant.example.test/dashboard')))->toBeNull()
        ->and($resolver->resolve(Request::create('https://sync-tenant-next.example.test/dashboard'))?->id())->toBe($tenant->id);
});

it('purges tenant domain lookup table when tenant is deleted', function (): void {
    $tenant = Tenant::query()->create([
        'slug' => 'sync-tenant-delete',
        'name' => 'Sync Tenant Delete',
        'domains' => ['sync-tenant-delete.example.test'],
    ]);

    $resolver = resolve(DomainTenantResolver::class);

    expect($resolver->resolve(Request::create('https://sync-tenant-delete.example.test/dashboard'))?->id())->toBe($tenant->id);

    $tenant->delete();

    expect($resolver->resolve(Request::create('https://sync-tenant-delete.example.test/dashboard')))->toBeNull();
});

it('keeps landlord domain lookup table in sync when domains change', function (): void {
    $landlord = Landlord::query()->create([
        'slug' => 'sync-landlord',
        'name' => 'Sync Landlord',
        'domains' => ['sync-landlord.example.test'],
    ]);

    $resolver = resolve(DomainLandlordResolver::class);

    expect($resolver->resolve(Request::create('https://sync-landlord.example.test/dashboard'))?->id())->toBe($landlord->id);

    $landlord->update([
        'domains' => ['sync-landlord-next.example.test'],
    ]);

    expect($resolver->resolve(Request::create('https://sync-landlord.example.test/dashboard')))->toBeNull()
        ->and($resolver->resolve(Request::create('https://sync-landlord-next.example.test/dashboard'))?->id())->toBe($landlord->id);
});

it('purges landlord domain lookup table when landlord is deleted', function (): void {
    $landlord = Landlord::query()->create([
        'slug' => 'sync-landlord-delete',
        'name' => 'Sync Landlord Delete',
        'domains' => ['sync-landlord-delete.example.test'],
    ]);

    $resolver = resolve(DomainLandlordResolver::class);

    expect($resolver->resolve(Request::create('https://sync-landlord-delete.example.test/dashboard'))?->id())->toBe($landlord->id);

    $landlord->delete();

    expect($resolver->resolve(Request::create('https://sync-landlord-delete.example.test/dashboard')))->toBeNull();
});
