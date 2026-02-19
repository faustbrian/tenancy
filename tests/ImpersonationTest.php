<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Tenancy\Contracts\TenancyInterface;
use Cline\Tenancy\Http\Middleware\TenantImpersonation;
use Cline\Tenancy\Models\Landlord;
use Cline\Tenancy\Models\Tenant;
use Cline\Tenancy\Support\TenantImpersonationManager;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Symfony\Component\HttpFoundation\Response;

it('issues and applies one-time tenant impersonation tokens', function (): void {
    $landlord = Landlord::query()->create([
        'slug' => 'division-impersonation',
        'name' => 'Division Impersonation',
    ]);

    $tenant = Tenant::query()->create([
        'slug' => 'tenant-impersonation',
        'name' => 'Tenant Impersonation',
        'landlord_id' => $landlord->id,
        'domains' => ['tenant-impersonation.example.test'],
    ]);

    $manager = resolve(TenantImpersonationManager::class);
    $tenancy = resolve(TenancyInterface::class);

    $token = $manager->issueToken($tenant, 42, 'web');

    expect($token)->toBeString()->not->toBe('');

    $payload = $manager->applyToken($token);

    expect($payload)->toBeArray()
        ->and($payload['user_id'])->toBe(42)
        ->and($payload['guard'])->toBe('web')
        ->and($tenancy->tenantId())->toBe($tenant->id);

    $missingPayload = $manager->applyToken($token);
    expect($missingPayload)->toBeNull();
});

it('applies impersonation token from middleware query parameter', function (): void {
    $landlord = Landlord::query()->create([
        'slug' => 'division-impersonation-middleware',
        'name' => 'Division Impersonation Middleware',
    ]);

    $tenant = Tenant::query()->create([
        'slug' => 'tenant-impersonation-middleware',
        'name' => 'Tenant Impersonation Middleware',
        'landlord_id' => $landlord->id,
        'domains' => ['tenant-impersonation-middleware.example.test'],
    ]);

    $manager = resolve(TenantImpersonationManager::class);
    $middleware = resolve(TenantImpersonation::class);
    $tenancy = resolve(TenancyInterface::class);
    $session = resolve(SessionManager::class)->driver();

    $token = $manager->issueToken($tenant, 99, 'api');
    $request = Request::create('https://app.example.test/dashboard', Symfony\Component\HttpFoundation\Request::METHOD_GET, [
        'tenant_impersonation' => $token,
    ]);
    $request->setLaravelSession($session);

    $response = $middleware->handle($request, static fn (): Response => new Response('ok', Response::HTTP_OK));

    expect($response->getStatusCode())->toBe(200)
        ->and($tenancy->tenantId())->toBe($tenant->id)
        ->and($session->get('tenancy.impersonation.user_id'))->toBe(99)
        ->and($session->get('tenancy.impersonation.guard'))->toBe('api');
});
