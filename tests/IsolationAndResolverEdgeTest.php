<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Tenancy\Contracts\TenancyInterface;
use Cline\Tenancy\Enums\IsolationMode;
use Cline\Tenancy\Models\Tenant;
use Cline\Tenancy\Resolvers\AuthenticatedTenantResolver;
use Cline\Tenancy\Resolvers\SessionTenantResolver;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;

it('switches and restores default database connection for separate database isolation', function (): void {
    config()->set('tenancy.isolation', IsolationMode::SEPARATE_DATABASE->value);
    config()->set('database.connections.tenant_alt', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    $tenant = Tenant::query()->create([
        'slug' => 'db-switch',
        'name' => 'DB Switch',
        'domains' => ['db-switch.example.test'],
        'database' => ['connection' => 'tenant_alt'],
    ]);

    expect(config('database.default'))->toBe('testing');

    resolve(TenancyInterface::class)->runAsTenant($tenant, static function (): void {
        expect(config('database.default'))->toBe('tenant_alt');
    });

    expect(config('database.default'))->toBe('testing');
});

it('falls back to configured tenancy database connection for separate database isolation', function (): void {
    config()->set('tenancy.isolation', IsolationMode::SEPARATE_DATABASE->value);
    config()->set('tenancy.database.connection', 'tenant_alt');
    config()->set('database.connections.tenant_alt', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    $tenant = Tenant::query()->create([
        'slug' => 'db-fallback',
        'name' => 'DB Fallback',
        'domains' => ['db-fallback.example.test'],
    ]);

    resolve(TenancyInterface::class)->runAsTenant($tenant, static function (): void {
        expect(config('database.default'))->toBe('tenant_alt');
    });

    expect(config('database.default'))->toBe('testing');
});

it('does not switch connection in shared database isolation', function (): void {
    config()->set('tenancy.isolation', IsolationMode::SHARED_DATABASE->value);
    config()->set('database.connections.tenant_alt', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    $tenant = Tenant::query()->create([
        'slug' => 'db-shared',
        'name' => 'DB Shared',
        'domains' => ['db-shared.example.test'],
        'database' => ['connection' => 'tenant_alt'],
    ]);

    resolve(TenancyInterface::class)->runAsTenant($tenant, static function (): void {
        expect(config('database.default'))->toBe('testing');
    });
});

it('resolves authenticated tenants using configured user attribute', function (): void {
    $tenant = Tenant::query()->create([
        'slug' => 'attribute-tenant',
        'name' => 'Attribute Tenant',
        'domains' => ['attribute.example.test'],
    ]);

    config()->set('tenancy.resolver.user_attribute', 'tenant_slug');

    $request = Request::create('https://app.example.test/dashboard');
    $request->setUserResolver(static fn (): object => (object) ['tenant_slug' => 'attribute-tenant']);

    $resolver = resolve(AuthenticatedTenantResolver::class);

    expect($resolver->resolve($request)?->id())->toBe($tenant->id);

    config()->set('tenancy.resolver.user_attribute', 'invalid');
    $request->setUserResolver(static fn (): object => (object) ['invalid' => ['bad']]);
    expect($resolver->resolve($request))->toBeNull();
});

it('resolves session tenants from array payload and handles invalid configuration', function (): void {
    $tenant = Tenant::query()->create([
        'slug' => 'session-array',
        'name' => 'Session Array',
        'domains' => ['session-array.example.test'],
    ]);

    $session = resolve(SessionManager::class)->driver();
    $request = Request::create('https://app.example.test/dashboard');
    $request->setLaravelSession($session);

    $resolver = resolve(SessionTenantResolver::class);

    $session->put('tenant', ['id' => $tenant->id]);
    expect($resolver->resolve($request)?->slug())->toBe('session-array');

    $session->put('tenant', ['slug' => 'session-array']);
    expect($resolver->resolve($request)?->slug())->toBe('session-array');

    config()->set('tenancy.resolver.session_key', []);
    expect($resolver->resolve($request))->toBeNull();
});
