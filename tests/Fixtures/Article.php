<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Fixtures;

use Cline\Tenancy\Concerns\BelongsToTenant;
use Cline\Tenancy\Concerns\HasTenantId;
use Illuminate\Database\Eloquent\Model;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class Article extends Model
{
    use BelongsToTenant;
    use HasTenantId;

    protected $table = 'articles';

    protected $guarded = [];
}
