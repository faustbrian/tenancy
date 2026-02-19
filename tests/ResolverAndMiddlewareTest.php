<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Tenancy\Contracts\TenancyInterface;
use Cline\Tenancy\Contracts\TenantInterface;
use Cline\Tenancy\Contracts\TenantResolverInterface;
use Cline\Tenancy\Events\LandlordEnded;
use Cline\Tenancy\Exceptions\UnexpectedMiddlewareResponseException;
use Cline\Tenancy\Http\Middleware\EnsureLandlordSessionScope;
use Cline\Tenancy\Http\Middleware\EnsureTenantSessionScope;
use Cline\Tenancy\Http\Middleware\OptionalLandlord;
use Cline\Tenancy\Http\Middleware\OptionalTenant;
use Cline\Tenancy\Http\Middleware\RequireLandlord;
use Cline\Tenancy\Http\Middleware\RequireTenant;
use Cline\Tenancy\Models\Landlord;
use Cline\Tenancy\Models\Tenant;
use Cline\Tenancy\Resolvers\AuthenticatedLandlordResolver;
use Cline\Tenancy\Resolvers\AuthenticatedTenantResolver;
use Cline\Tenancy\Resolvers\ChainLandlordResolver;
use Cline\Tenancy\Resolvers\ChainTenantResolver;
use Cline\Tenancy\Resolvers\DomainLandlordResolver;
use Cline\Tenancy\Resolvers\DomainTenantResolver;
use Cline\Tenancy\Resolvers\HeaderLandlordResolver;
use Cline\Tenancy\Resolvers\HeaderTenantResolver;
use Cline\Tenancy\Resolvers\PathLandlordResolver;
use Cline\Tenancy\Resolvers\PathTenantResolver;
use Cline\Tenancy\Resolvers\SessionLandlordResolver;
use Cline\Tenancy\Resolvers\SessionTenantResolver;
use Cline\Tenancy\Resolvers\SubdomainLandlordResolver;
use Cline\Tenancy\Resolvers\SubdomainTenantResolver;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('covers resolver chain and resolver variants', function (): void {
    Tenant::query()->create([
        'slug' => 'acme',
        'name' => 'Acme',
        'domains' => ['acme.example.test'],
    ]);

    $domainResolver = resolve(DomainTenantResolver::class);
    $headerResolver = resolve(HeaderTenantResolver::class);
    $pathResolver = resolve(PathTenantResolver::class);
    $subdomainResolver = resolve(SubdomainTenantResolver::class);

    $domainRequest = Request::create('https://acme.example.test/dashboard');
    $headerRequest = Request::create('https://app.example.test/dashboard');
    $headerRequest->headers->set('X-Tenant', 'acme');

    $pathRequest = Request::create('https://app.example.test/acme/dashboard');
    $subdomainRequest = Request::create('https://acme.app.example.test/dashboard');

    expect($domainResolver->resolve($domainRequest)?->slug())->toBe('acme')
        ->and($headerResolver->resolve($headerRequest)?->slug())->toBe('acme')
        ->and($pathResolver->resolve($pathRequest)?->slug())->toBe('acme')
        ->and($subdomainResolver->resolve($subdomainRequest)?->slug())->toBe('acme');

    config()->set('tenancy.resolver.header', []);
    config()->set('tenancy.resolver.path_segment', 'bad');

    expect($headerResolver->resolve($headerRequest))->toBeNull()
        ->and($pathResolver->resolve($pathRequest)?->slug())->toBe('acme');

    $chain = new ChainTenantResolver([
        new class() implements TenantResolverInterface
        {
            public function resolve(Request $request): ?TenantInterface
            {
                return null;
            }
        },
        $headerResolver,
        $domainResolver,
    ]);

    expect($chain->resolve($domainRequest)?->slug())->toBe('acme');
});

it('resolves tenant from authenticated user and session', function (): void {
    $tenant = Tenant::query()->create([
        'slug' => 'auth-tenant',
        'name' => 'Auth Tenant',
        'domains' => ['auth.example.test'],
    ]);

    $authenticatedResolver = resolve(AuthenticatedTenantResolver::class);
    $sessionResolver = resolve(SessionTenantResolver::class);

    $authRequest = Request::create('https://app.example.test/dashboard');
    $authRequest->setUserResolver(static fn (): Tenant => $tenant);

    expect($authenticatedResolver->resolve($authRequest)?->slug())->toBe('auth-tenant');

    $session = resolve(SessionManager::class)->driver();
    $session->put('tenant', 'auth-tenant');

    $sessionRequest = Request::create('https://app.example.test/dashboard');
    $sessionRequest->setLaravelSession($session);

    expect($sessionResolver->resolve($sessionRequest)?->slug())->toBe('auth-tenant');
});

it('covers landlord resolver chain and resolver variants', function (): void {
    Landlord::query()->create([
        'slug' => 'division',
        'name' => 'Division',
    ]);

    $headerResolver = resolve(HeaderLandlordResolver::class);
    $pathResolver = resolve(PathLandlordResolver::class);
    $subdomainResolver = resolve(SubdomainLandlordResolver::class);
    $authenticatedResolver = resolve(AuthenticatedLandlordResolver::class);
    $sessionResolver = resolve(SessionLandlordResolver::class);

    $headerRequest = Request::create('https://app.example.test/dashboard');
    $headerRequest->headers->set('X-Landlord', 'division');

    $pathRequest = Request::create('https://app.example.test/division/dashboard');
    $subdomainRequest = Request::create('https://division.app.example.test/dashboard');

    expect($headerResolver->resolve($headerRequest)?->slug())->toBe('division')
        ->and($pathResolver->resolve($pathRequest)?->slug())->toBe('division')
        ->and($subdomainResolver->resolve($subdomainRequest)?->slug())->toBe('division');

    $authRequest = Request::create('https://app.example.test/dashboard');
    $authRequest->setUserResolver(static fn (): Landlord => Landlord::query()->firstOrFail());

    expect($authenticatedResolver->resolve($authRequest)?->slug())->toBe('division');

    $session = resolve(SessionManager::class)->driver();
    $session->put('landlord', ['slug' => 'division']);

    $sessionRequest = Request::create('https://app.example.test/dashboard');
    $sessionRequest->setLaravelSession($session);

    expect($sessionResolver->resolve($sessionRequest)?->slug())->toBe('division');

    config()->set('tenancy.landlord.resolver.header', []);
    config()->set('tenancy.landlord.resolver.path_segment', 'bad');

    expect($headerResolver->resolve($headerRequest))->toBeNull()
        ->and($pathResolver->resolve($pathRequest)?->slug())->toBe('division');

    $chain = new ChainLandlordResolver([$headerResolver, $subdomainResolver]);
    expect($chain->resolve($subdomainRequest)?->slug())->toBe('division');
});

it('resolves landlord domains from normalized lookup table', function (): void {
    $landlord = Landlord::query()->create([
        'slug' => 'division-domain',
        'name' => 'Division Domain',
        'domains' => [],
    ]);

    DB::table('landlord_domains')->insert([
        'landlord_id' => $landlord->id,
        'domain' => 'division.example.test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $domainResolver = resolve(DomainLandlordResolver::class);
    $request = Request::create('https://division.example.test/dashboard');

    expect($domainResolver->resolve($request)?->slug())->toBe('division-domain');
});

it('skips landlord domain and subdomain resolution on central domains', function (): void {
    Landlord::query()->create([
        'slug' => 'division-central',
        'name' => 'Division Central',
        'domains' => ['central.example.test'],
    ]);

    config()->set('tenancy.landlord.resolver.central_domains', ['central.example.test']);

    $domainRequest = Request::create('https://central.example.test/dashboard');
    $subdomainRequest = Request::create('https://division-central.central.example.test/dashboard');

    expect(resolve(DomainLandlordResolver::class)->resolve($domainRequest))->toBeNull()
        ->and(resolve(SubdomainLandlordResolver::class)->resolve($subdomainRequest))->toBeNull();
});

it('matches domains case-insensitively and with trailing dots', function (): void {
    Tenant::query()->create([
        'slug' => 'canonical-tenant',
        'name' => 'Canonical Tenant',
        'domains' => ['Canonical.Example.Test.'],
    ]);

    Landlord::query()->create([
        'slug' => 'canonical-division',
        'name' => 'Canonical Division',
        'domains' => ['Division.Example.Test.'],
    ]);

    $tenantRequest = Request::create('https://canonical.example.test./dashboard');
    $landlordRequest = Request::create('https://division.example.test/dashboard');

    expect(resolve(DomainTenantResolver::class)->resolve($tenantRequest)?->slug())->toBe('canonical-tenant')
        ->and(resolve(DomainLandlordResolver::class)->resolve($landlordRequest)?->slug())->toBe('canonical-division');
});

it('resolves tenant domains from normalized lookup table', function (): void {
    $tenant = Tenant::query()->create([
        'slug' => 'table-domain',
        'name' => 'Table Domain',
        'domains' => [],
    ]);

    DB::table('tenant_domains')->insert([
        'tenant_id' => $tenant->id,
        'domain' => 'table.example.test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $domainResolver = resolve(DomainTenantResolver::class);
    $request = Request::create('https://table.example.test/dashboard');

    expect($domainResolver->resolve($request)?->slug())->toBe('table-domain');
});

it('skips tenant domain and subdomain resolution on central domains', function (): void {
    Tenant::query()->create([
        'slug' => 'tenant-central',
        'name' => 'Tenant Central',
        'domains' => ['tenant-central.example.test'],
    ]);

    config()->set('tenancy.resolver.central_domains', ['tenant-central.example.test']);

    $domainRequest = Request::create('https://tenant-central.example.test/dashboard');
    $subdomainRequest = Request::create('https://tenant-central.tenant-central.example.test/dashboard');

    expect(resolve(DomainTenantResolver::class)->resolve($domainRequest))->toBeNull()
        ->and(resolve(SubdomainTenantResolver::class)->resolve($subdomainRequest))->toBeNull();
});

it('covers tenant middlewares and cleanup behavior', function (): void {
    $landlord = Landlord::query()->create([
        'slug' => 'division-middleware',
        'name' => 'Division Middleware',
    ]);

    Tenant::query()->create([
        'slug' => 'acme',
        'name' => 'Acme',
        'domains' => ['acme.example.test'],
        'landlord_id' => $landlord->id,
    ]);

    $tenancy = resolve(TenancyInterface::class);
    $optional = resolve(OptionalTenant::class);
    $required = resolve(RequireTenant::class);

    $okRequest = Request::create('https://acme.example.test/dashboard');
    $missingRequest = Request::create('https://missing.example.test/dashboard');

    $optionalResponse = $optional->handle($okRequest, function () use ($tenancy): Response {
        expect($tenancy->tenantId())->toBe(1);

        return new Response('ok', Response::HTTP_OK);
    });

    expect($optionalResponse->getStatusCode())->toBe(200)
        ->and($tenancy->currentTenant())->toBeNull()
        ->and($tenancy->currentLandlord())->toBeNull();

    $requiredResponse = $required->handle($okRequest, fn (): Response => new Response('ok', Response::HTTP_OK));

    expect($requiredResponse->getStatusCode())->toBe(200)
        ->and($tenancy->currentTenant())->toBeNull()
        ->and($tenancy->currentLandlord())->toBeNull();

    config()->set('tenancy.http.abort_status', 404);

    try {
        $required->handle($missingRequest, fn (): Response => new Response('ok', Response::HTTP_OK));
        $this->fail('Expected an HttpException to be thrown.');
    } catch (HttpException $httpException) {
        expect($httpException->getStatusCode())->toBe(404)
            ->and($httpException->getMessage())->toContain('Tenant not resolved for host');
    }
});

it('covers landlord middlewares and cleanup behavior', function (): void {
    Landlord::query()->create([
        'slug' => 'division-landlord-middleware',
        'name' => 'Division Middleware',
        'domains' => ['division-landlord-middleware.example.test'],
    ]);

    $tenancy = resolve(TenancyInterface::class);
    $optional = resolve(OptionalLandlord::class);
    $required = resolve(RequireLandlord::class);

    $okRequest = Request::create('https://division-landlord-middleware.example.test/dashboard');
    $missingRequest = Request::create('https://missing.example.test/dashboard');

    $optionalResponse = $optional->handle($okRequest, function () use ($tenancy): Response {
        expect($tenancy->landlordId())->toBe(1);

        return new Response('ok', Response::HTTP_OK);
    });

    expect($optionalResponse->getStatusCode())->toBe(200)
        ->and($tenancy->currentLandlord())->toBeNull()
        ->and($tenancy->currentTenant())->toBeNull();

    $requiredResponse = $required->handle($okRequest, fn (): Response => new Response('ok', Response::HTTP_OK));

    expect($requiredResponse->getStatusCode())->toBe(200)
        ->and($tenancy->currentLandlord())->toBeNull()
        ->and($tenancy->currentTenant())->toBeNull();

    config()->set('tenancy.http.abort_status', 404);

    try {
        $required->handle($missingRequest, fn (): Response => new Response('ok', Response::HTTP_OK));
        $this->fail('Expected an HttpException to be thrown.');
    } catch (HttpException $httpException) {
        expect($httpException->getStatusCode())->toBe(404)
            ->and($httpException->getMessage())->toContain('Landlord not resolved for host');
    }
});

it('throws a typed exception when required middleware callback returns non-response', function (): void {
    $landlord = Landlord::query()->create([
        'slug' => 'division-middleware-response',
        'name' => 'Division Middleware Response',
        'domains' => ['division-middleware-response.example.test'],
    ]);

    Tenant::query()->create([
        'slug' => 'tenant-middleware-response',
        'name' => 'Tenant Middleware Response',
        'domains' => ['tenant-middleware-response.example.test'],
        'landlord_id' => $landlord->id,
    ]);

    $requiredTenant = resolve(RequireTenant::class);
    $requiredLandlord = resolve(RequireLandlord::class);

    $tenantRequest = Request::create('https://tenant-middleware-response.example.test/dashboard');
    $landlordRequest = Request::create('https://division-middleware-response.example.test/dashboard');

    expect(fn (): mixed => $requiredTenant->handle($tenantRequest, fn (): string => 'invalid'))
        ->toThrow(UnexpectedMiddlewareResponseException::class, 'Expected a Symfony response instance.');

    expect(fn (): mixed => $requiredLandlord->handle($landlordRequest, fn (): string => 'invalid'))
        ->toThrow(UnexpectedMiddlewareResponseException::class, 'Expected a Symfony response instance.');
});

it('dispatches landlord ended once when landlord is forgotten repeatedly', function (): void {
    Event::fake([LandlordEnded::class]);

    $landlord = Landlord::query()->create([
        'slug' => 'division-once',
        'name' => 'Division Once',
    ]);

    Tenant::query()->create([
        'slug' => 'tenant-once',
        'name' => 'Tenant Once',
        'landlord_id' => $landlord->id,
        'domains' => ['tenant-once.example.test'],
    ]);

    $tenancy = resolve(TenancyInterface::class);
    $tenancy->resolveTenant(Request::create('https://tenant-once.example.test/dashboard'));

    $tenancy->forgetCurrentTenant();
    $tenancy->forgetCurrentLandlord();

    Event::assertDispatchedTimes(LandlordEnded::class, 1);
});

it('guards tenant and landlord session scope across context changes', function (): void {
    config()->set('tenancy.session.invalidate_on_mismatch', false);

    $landlordA = Landlord::query()->create([
        'slug' => 'division-session-a',
        'name' => 'Division Session A',
        'domains' => ['division-session-a.example.test'],
    ]);

    $landlordB = Landlord::query()->create([
        'slug' => 'division-session-b',
        'name' => 'Division Session B',
        'domains' => ['division-session-b.example.test'],
    ]);

    $tenantA = Tenant::query()->create([
        'slug' => 'tenant-session-a',
        'name' => 'Tenant Session A',
        'landlord_id' => $landlordA->id,
        'domains' => ['tenant-session-a.example.test'],
    ]);

    $tenantB = Tenant::query()->create([
        'slug' => 'tenant-session-b',
        'name' => 'Tenant Session B',
        'landlord_id' => $landlordB->id,
        'domains' => ['tenant-session-b.example.test'],
    ]);

    $tenancy = resolve(TenancyInterface::class);
    $session = resolve(SessionManager::class)->driver();
    $tenantScope = resolve(EnsureTenantSessionScope::class);
    $landlordScope = resolve(EnsureLandlordSessionScope::class);

    $tenantRequest = Request::create('https://tenant-session-a.example.test/dashboard');
    $tenantRequest->setLaravelSession($session);

    $landlordRequest = Request::create('https://division-session-a.example.test/dashboard');
    $landlordRequest->setLaravelSession($session);

    $tenancy->runAsTenant($tenantA, static function () use ($tenantScope, $tenantRequest): void {
        $tenantScope->handle($tenantRequest, static fn (): Response => new Response('ok', Response::HTTP_OK));
    });

    $tenancy->runAsLandlord($landlordA, static function () use ($landlordScope, $landlordRequest): void {
        $landlordScope->handle($landlordRequest, static fn (): Response => new Response('ok', Response::HTTP_OK));
    });

    expect($session->get('tenancy.tenant_id'))->toBe((string) $tenantA->id)
        ->and($session->get('tenancy.landlord_id'))->toBe((string) $landlordA->id);

    expect(fn (): mixed => $tenancy->runAsTenant($tenantB, static function () use ($tenantScope, $tenantRequest): void {
        $tenantScope->handle($tenantRequest, static fn (): Response => new Response('ok', Response::HTTP_OK));
    }))->toThrow(HttpException::class, 'Tenant session scope mismatch.');

    expect(fn (): mixed => $tenancy->runAsLandlord($landlordB, static function () use ($landlordScope, $landlordRequest): void {
        $landlordScope->handle($landlordRequest, static fn (): Response => new Response('ok', Response::HTTP_OK));
    }))->toThrow(HttpException::class, 'Landlord session scope mismatch.');
});
