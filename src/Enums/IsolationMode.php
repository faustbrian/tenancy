<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Enums;

/**
 * Represents the database isolation strategy used to separate tenant data.
 *
 * The chosen mode determines how the package switches database context when
 * a tenant is resolved and which bootstrapping tasks (e.g. schema prefix,
 * connection swap) are executed at the start of each tenanted request.
 *
 * @author Brian Faust <brian@cline.sh>
 */
enum IsolationMode: string
{
    /**
     * All tenants share a single database and schema.
     *
     * Tenant rows are distinguished by a tenant identifier column on each
     * table. No connection or schema switching occurs; query scopes are
     * responsible for filtering data to the active tenant.
     */
    case SHARED_DATABASE = 'shared-database';

    /**
     * Each tenant owns a dedicated schema within a shared database server.
     *
     * The package switches the active search path or schema prefix when a
     * tenant is resolved, keeping tenant data physically separated while
     * still sharing the same database instance.
     */
    case SEPARATE_SCHEMA = 'separate-schema';

    /**
     * Each tenant is provisioned a fully independent database.
     *
     * The package swaps the active database connection when a tenant is
     * resolved, providing the strongest isolation boundary at the cost of
     * additional infrastructure overhead.
     */
    case SEPARATE_DATABASE = 'separate-database';
}
