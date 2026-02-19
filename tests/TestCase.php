<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests;

use Cline\Tenancy\Models\Landlord;
use Cline\Tenancy\Models\Tenant;
use Cline\Tenancy\TenancyServiceProvider;
use Cline\VariableKeys\Enums\PrimaryKeyType;
use Cline\VariableKeys\Facades\VariableKeys;
use Cline\VariableKeys\VariableKeysServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

use function env;

/**
 * @author Brian Faust <brian@cline.sh>
 * @internal
 */
abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            VariableKeysServiceProvider::class,
            TenancyServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app->make(Repository::class)->set('database.default', 'testing');
        $app->make(Repository::class)->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app->make(Repository::class)->set('tenancy.primary_key_type', env('TENANCY_PRIMARY_KEY_TYPE', 'id'));

        $primaryKeyType = PrimaryKeyType::from(env('TENANCY_PRIMARY_KEY_TYPE', 'id'));

        VariableKeys::map([
            Tenant::class => [
                'primary_key_type' => $primaryKeyType,
            ],
            Landlord::class => [
                'primary_key_type' => $primaryKeyType,
            ],
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('landlords', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->json('domains')->nullable();
            $table->json('database')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::create('landlord_domains', function (Blueprint $table): void {
            $table->foreignId('landlord_id')->constrained('landlords')->cascadeOnDelete();
            $table->string('domain')->unique();
            $table->timestamps();
        });

        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('landlord_id')->nullable()->constrained('landlords')->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('name');
            $table->json('domains')->nullable();
            $table->json('features')->nullable();
            $table->json('database')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_domains', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('domain')->unique();
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->string('title');
            $table->timestamps();
        });
    }
}
