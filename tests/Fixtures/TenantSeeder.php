<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Fixtures;

use Illuminate\Database\Seeder;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->line('tenant-seeder');
    }
}
