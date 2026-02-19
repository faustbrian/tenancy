<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Tenancy\Contracts\LandlordRepositoryInterface;
use Cline\Tenancy\Contracts\TenancyInterface;
use Cline\Tenancy\Enums\IsolationMode;
use Cline\Tenancy\Models\Landlord;
use Cline\Tenancy\Tasks\SwitchLandlordDatabaseTask;

it('covers landlord repository identifier and domain lookups', function (): void {
    $repository = resolve(LandlordRepositoryInterface::class);

    $created = $repository->create([
        'slug' => 'division-repo',
        'name' => 'Division Repo',
        'domains' => ['division-repo.example.test'],
    ]);

    expect($repository->findById($created->id())?->slug())->toBe('division-repo')
        ->and($repository->findBySlug('division-repo')?->id())->toBe($created->id())
        ->and($repository->findByIdentifier((string) $created->id())?->slug())->toBe('division-repo')
        ->and($repository->findByIdentifier('division-repo')?->id())->toBe($created->id())
        ->and($repository->findByDomain('division-repo.example.test')?->slug())->toBe('division-repo');

    $all = [];

    foreach ($repository->all() as $landlord) {
        $all[] = $landlord->slug();
    }

    expect($all)->toContain('division-repo');
});

it('prefers slug lookup for string identifiers that are numeric', function (): void {
    $repository = resolve(LandlordRepositoryInterface::class);

    $slugLandlord = $repository->create([
        'slug' => '123',
        'name' => 'Slug 123',
    ]);

    $idLandlord = $repository->create([
        'id' => 123,
        'slug' => 'id-123',
        'name' => 'ID 123',
    ]);

    expect($repository->findByIdentifier('123')?->id())->toBe($slugLandlord->id())
        ->and($repository->findByIdentifier(123)?->id())->toBe($idLandlord->id());
});

it('falls back to json landlord domains when lookup table is unavailable', function (): void {
    $repository = resolve(LandlordRepositoryInterface::class);

    Landlord::query()->create([
        'slug' => 'division-json',
        'name' => 'Division Json',
        'domains' => ['division-json.example.test'],
    ]);

    config()->set('tenancy.landlord.domain_lookup.use_table', false);

    expect($repository->findByDomain('division-json.example.test')?->slug())->toBe('division-json');

    config()->set('tenancy.landlord.domain_lookup.use_table', true);
    config()->set('tenancy.table_names.landlord_domains', 'unknown_landlord_domains');

    expect($repository->findByDomain('division-json.example.test')?->slug())->toBe('division-json')
        ->and($repository->findByDomain('missing.example.test'))->toBeNull();
});

it('can cache landlord domain lookups', function (): void {
    $repository = resolve(LandlordRepositoryInterface::class);

    $landlord = $repository->create([
        'slug' => 'division-cached',
        'name' => 'Division Cached',
        'domains' => ['division-cached.example.test'],
    ]);

    config()->set('tenancy.landlord.domain_lookup.cache.enabled', true);
    config()->set('tenancy.landlord.domain_lookup.cache.ttl_seconds', 600);
    config()->set('tenancy.landlord.domain_lookup.cache.prefix', 'tests:landlord:domain:');

    expect($repository->findByDomain('division-cached.example.test')?->id())->toBe($landlord->id());

    $landlord->update([
        'domains' => [],
    ]);

    expect($repository->findByDomain('division-cached.example.test')?->id())->toBe($landlord->id());
});

it('switches and restores default database connection for landlord isolation', function (): void {
    config()->set('tenancy.landlord.isolation', IsolationMode::SEPARATE_DATABASE->value);
    config()->set('tenancy.landlord.database.connection', 'tenant_alt');
    config()->set('database.connections.tenant_alt', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    $landlord = Landlord::query()->create([
        'slug' => 'division-db',
        'name' => 'Division DB',
        'database' => ['connection' => 'tenant_alt'],
    ]);

    $context = resolve(TenancyInterface::class)->landlord($landlord);
    $task = resolve(SwitchLandlordDatabaseTask::class);

    expect($context)->not->toBeNull()
        ->and(config('database.default'))->toBe('testing');

    $task->makeCurrent($context);

    expect(config('database.default'))->toBe('tenant_alt');

    $task->forgetCurrent($context);

    expect(config('database.default'))->toBe('testing');
});

it('does not switch landlord database in shared isolation mode', function (): void {
    config()->set('tenancy.landlord.isolation', IsolationMode::SHARED_DATABASE->value);

    $landlord = Landlord::query()->create([
        'slug' => 'division-shared',
        'name' => 'Division Shared',
    ]);

    $context = resolve(TenancyInterface::class)->landlord($landlord);
    $task = resolve(SwitchLandlordDatabaseTask::class);

    expect($context)->not->toBeNull();

    $task->makeCurrent($context);
    $task->forgetCurrent($context);

    expect(config('database.default'))->toBe('testing');
});
