<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Tenancy\Models\Landlord;
use Cline\Tenancy\Models\Tenant;
use Tests\Exceptions\LandlordFailFastException;
use Tests\Exceptions\LandlordNotFailFastException;
use Tests\Exceptions\TenantFailFastException;
use Tests\Exceptions\TenantNotFailFastException;

use function Cline\Tenancy\tenancy_scheduler;

it('stops on first tenant scheduler callback failure when fail_fast is true', function (): void {
    config()->set('tenancy.scheduler.fail_fast', true);

    Tenant::query()->create(['slug' => 'scheduler-fast-a', 'name' => 'A', 'domains' => ['a.example.test']]);
    Tenant::query()->create(['slug' => 'scheduler-fast-b', 'name' => 'B', 'domains' => ['b.example.test']]);

    $seen = [];

    try {
        tenancy_scheduler()->eachTenant(function (Tenant $tenant) use (&$seen): void {
            $seen[] = $tenant->slug();

            throw TenantFailFastException::make();
        });

        expect()->fail('Expected tenant scheduler to throw in fail-fast mode.');
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getMessage())->toBe('tenant-fail-fast');
    }

    expect($seen)->toBe(['scheduler-fast-a']);
});

it('continues tenant scheduler callbacks and throws after loop when not fail fast', function (): void {
    config()->set('tenancy.scheduler.fail_fast', false);

    Tenant::query()->create(['slug' => 'scheduler-soft-a', 'name' => 'A', 'domains' => ['a-soft.example.test']]);
    Tenant::query()->create(['slug' => 'scheduler-soft-b', 'name' => 'B', 'domains' => ['b-soft.example.test']]);

    $seen = [];

    try {
        tenancy_scheduler()->eachTenant(function (Tenant $tenant) use (&$seen): void {
            $seen[] = $tenant->slug();

            throw_if($tenant->slug() === 'scheduler-soft-a', TenantNotFailFastException::make());
        });

        expect()->fail('Expected tenant scheduler to rethrow after non-fail-fast loop.');
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getMessage())->toBe('tenant-not-fail-fast');
    }

    expect($seen)->toBe(['scheduler-soft-a', 'scheduler-soft-b']);
});

it('stops on first landlord scheduler callback failure when fail_fast is true', function (): void {
    config()->set('tenancy.scheduler.fail_fast', true);

    Landlord::query()->create(['slug' => 'scheduler-landlord-fast-a', 'name' => 'A']);
    Landlord::query()->create(['slug' => 'scheduler-landlord-fast-b', 'name' => 'B']);

    $seen = [];

    try {
        tenancy_scheduler()->eachLandlord(function (Landlord $landlord) use (&$seen): void {
            $seen[] = $landlord->slug();

            throw LandlordFailFastException::make();
        });

        expect()->fail('Expected landlord scheduler to throw in fail-fast mode.');
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getMessage())->toBe('landlord-fail-fast');
    }

    expect($seen)->toBe(['scheduler-landlord-fast-a']);
});

it('continues landlord scheduler callbacks and throws after loop when not fail fast', function (): void {
    config()->set('tenancy.scheduler.fail_fast', false);

    Landlord::query()->create(['slug' => 'scheduler-landlord-soft-a', 'name' => 'A']);
    Landlord::query()->create(['slug' => 'scheduler-landlord-soft-b', 'name' => 'B']);

    $seen = [];

    try {
        tenancy_scheduler()->eachLandlord(function (Landlord $landlord) use (&$seen): void {
            $seen[] = $landlord->slug();

            throw_if($landlord->slug() === 'scheduler-landlord-soft-a', LandlordNotFailFastException::make());
        });

        expect()->fail('Expected landlord scheduler to rethrow after non-fail-fast loop.');
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getMessage())->toBe('landlord-not-fail-fast');
    }

    expect($seen)->toBe(['scheduler-landlord-soft-a', 'scheduler-landlord-soft-b']);
});
