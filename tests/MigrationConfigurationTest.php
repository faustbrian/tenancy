<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('uses configurable table names in tenancy migration stub', function (): void {
    Schema::dropIfExists('tenants');
    Schema::dropIfExists('landlords');
    Schema::dropIfExists('tenant_domains');
    Schema::dropIfExists('landlord_domains');

    config()->set('tenancy.table_names.landlords', 'division_records');
    config()->set('tenancy.table_names.landlord_domains', 'division_domain_records');
    config()->set('tenancy.table_names.tenants', 'tenant_records');
    config()->set('tenancy.table_names.tenant_domains', 'tenant_domain_records');

    $migration = require __DIR__.'/../database/migrations/create_tenancy_tables.php.stub';

    $migration->up();

    expect(Schema::hasTable('division_records'))->toBeTrue()
        ->and(Schema::hasTable('division_domain_records'))->toBeTrue()
        ->and(Schema::hasTable('tenant_records'))->toBeTrue()
        ->and(Schema::hasTable('tenant_domain_records'))->toBeTrue()
        ->and(Schema::hasColumn('tenant_records', 'landlord_id'))->toBeTrue();

    $migration->down();

    expect(Schema::hasTable('division_records'))->toBeFalse()
        ->and(Schema::hasTable('division_domain_records'))->toBeFalse()
        ->and(Schema::hasTable('tenant_records'))->toBeFalse()
        ->and(Schema::hasTable('tenant_domain_records'))->toBeFalse();
});

it('uses configurable primary key types in tenancy migration stub', function (): void {
    Schema::dropIfExists('tenants');
    Schema::dropIfExists('landlords');
    Schema::dropIfExists('tenant_domains');
    Schema::dropIfExists('landlord_domains');

    config()->set('tenancy.primary_key_type', 'uuid');
    config()->set('tenancy.table_names.landlords', 'division_uuid_records');
    config()->set('tenancy.table_names.landlord_domains', 'division_domain_uuid_records');
    config()->set('tenancy.table_names.tenants', 'tenant_uuid_records');

    $migration = require __DIR__.'/../database/migrations/create_tenancy_tables.php.stub';

    $migration->up();

    expect(Schema::hasTable('division_uuid_records'))->toBeTrue()
        ->and(Schema::hasTable('tenant_uuid_records'))->toBeTrue();

    $landlordColumns = DB::select("PRAGMA table_info('division_uuid_records')");
    $tenantColumns = DB::select("PRAGMA table_info('tenant_uuid_records')");

    $landlordIdType = collect($landlordColumns)->firstWhere('name', 'id')->type ?? null;
    $tenantLandlordIdType = collect($tenantColumns)->firstWhere('name', 'landlord_id')->type ?? null;

    expect($landlordIdType)->not->toBe('INTEGER')
        ->and($tenantLandlordIdType)->not->toBe('INTEGER');

    $migration->down();
});
