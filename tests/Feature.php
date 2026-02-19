<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Tenancy\Contracts\TenancyInterface;
use Cline\Tenancy\Enums\IsolationMode;
use Cline\Tenancy\Exceptions\MissingCurrentTenant;
use Cline\Tenancy\Models\Tenant;
use Illuminate\Http\Request;
use Tests\Fixtures\Article;
use Tests\Fixtures\Post;

it('resolves tenant by domain and exposes context', function (): void {
    Tenant::query()->create([
        'slug' => 'acme',
        'name' => 'Acme',
        'domains' => ['acme.example.test'],
    ]);

    $context = resolve(TenancyInterface::class)->resolveTenant(Request::create('https://acme.example.test/dashboard'));

    expect($context)->not->toBeNull()
        ->and(resolve(TenancyInterface::class)->tenantId())->toBe(1)
        ->and(resolve(TenancyInterface::class)->currentTenant()?->slug())->toBe('acme');
});

it('supports explicit run and restores previous context after execution', function (): void {
    $tenant = Tenant::query()->create([
        'slug' => 'beta',
        'name' => 'Beta',
        'domains' => ['beta.example.test'],
    ]);

    $result = resolve(TenancyInterface::class)->runAsTenant($tenant, fn (): string => (string) resolve(TenancyInterface::class)->tenantId());

    expect($result)->toBe('1')
        ->and(resolve(TenancyInterface::class)->currentTenant())->toBeNull();
});

it('scopes models by tenant and auto-assigns tenant id on create', function (): void {
    $a = Tenant::query()->create([
        'slug' => 'a',
        'name' => 'Tenant A',
        'domains' => ['a.example.test'],
    ]);

    $b = Tenant::query()->create([
        'slug' => 'b',
        'name' => 'Tenant B',
        'domains' => ['b.example.test'],
    ]);

    resolve(TenancyInterface::class)->runAsTenant($a, static function (): void {
        Post::query()->create(['title' => 'A']);
    });

    resolve(TenancyInterface::class)->runAsTenant($b, static function (): void {
        Post::query()->create(['title' => 'B']);
    });

    $titlesForA = resolve(TenancyInterface::class)->runAsTenant($a, static fn (): array => Post::query()->pluck('title')->all());
    $titlesForB = resolve(TenancyInterface::class)->runAsTenant($b, static fn (): array => Post::query()->pluck('title')->all());
    $all = Post::query()->withoutTenantScope()->pluck('title')->all();

    expect($titlesForA)->toBe(['A'])
        ->and($titlesForB)->toBe(['B'])
        ->and($all)->toBe(['A', 'B']);
});

it('builds tenant-scoped queue names', function (): void {
    $tenant = Tenant::query()->create([
        'slug' => 'queue',
        'name' => 'Queue Tenant',
        'domains' => ['queue.example.test'],
    ]);

    $queue = resolve(TenancyInterface::class)->tenantScopedQueue('emails', $tenant);

    expect($queue)->toBe('tenant:1:emails');
});

it('returns configured isolation mode', function (): void {
    config()->set('tenancy.isolation', IsolationMode::SEPARATE_DATABASE->value);

    expect(resolve(TenancyInterface::class)->tenantIsolation())->toBe(IsolationMode::SEPARATE_DATABASE);
});

it('can require an active tenant for tenant-scoped models', function (): void {
    config()->set('tenancy.scoping.require_current_tenant', true);

    expect(fn (): mixed => Post::query()->get())->toThrow(MissingCurrentTenant::class);
    expect(fn (): mixed => Post::query()->create(['title' => 'No Tenant']))->toThrow(MissingCurrentTenant::class);
});

it('supports a configurable tenant foreign key for scoped models', function (): void {
    config()->set('tenancy.scoping.tenant_foreign_key', 'organization_id');

    $tenant = Tenant::query()->create([
        'slug' => 'org-a',
        'name' => 'Org A',
        'domains' => ['org-a.example.test'],
    ]);

    resolve(TenancyInterface::class)->runAsTenant($tenant, static function (): void {
        Article::query()->create(['title' => 'Scoped Article']);
    });

    expect(Article::query()->first()?->getAttribute('organization_id'))->toBe($tenant->id)
        ->and(Article::query()->first()?->tenant()->getForeignKeyName())->toBe('organization_id');
});
