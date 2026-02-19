<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Tenancy\Contracts\TenantRepositoryInterface;

it('prefers slug lookup for string identifiers that are numeric for tenants', function (): void {
    $repository = resolve(TenantRepositoryInterface::class);

    $slugTenant = $repository->create([
        'slug' => '123',
        'name' => 'Slug 123',
        'domains' => ['slug-123.example.test'],
    ]);

    $idTenant = $repository->create([
        'id' => 123,
        'slug' => 'id-123',
        'name' => 'ID 123',
        'domains' => ['id-123.example.test'],
    ]);

    expect($repository->findByIdentifier('123')?->id())->toBe($slugTenant->id())
        ->and($repository->findByIdentifier(123)?->id())->toBe($idTenant->id());
});

it('can cache tenant domain lookups', function (): void {
    $repository = resolve(TenantRepositoryInterface::class);

    $tenant = $repository->create([
        'slug' => 'tenant-cached',
        'name' => 'Tenant Cached',
        'domains' => ['tenant-cached.example.test'],
    ]);

    config()->set('tenancy.domain_lookup.cache.enabled', true);
    config()->set('tenancy.domain_lookup.cache.ttl_seconds', 600);
    config()->set('tenancy.domain_lookup.cache.prefix', 'tests:tenant:domain:');

    expect($repository->findByDomain('tenant-cached.example.test')?->id())->toBe($tenant->id());

    $tenant->update([
        'domains' => [],
    ]);

    expect($repository->findByDomain('tenant-cached.example.test')?->id())->toBe($tenant->id());
});
