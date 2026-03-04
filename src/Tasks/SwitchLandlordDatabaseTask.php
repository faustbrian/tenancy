<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Tasks;

use Cline\Tenancy\Contracts\DatabaseAwareLandlordInterface;
use Cline\Tenancy\Contracts\LandlordTaskInterface;
use Cline\Tenancy\Enums\IsolationMode;
use Cline\Tenancy\LandlordContext;
use Illuminate\Database\DatabaseManager;

use function array_pop;
use function config;
use function is_array;
use function is_string;

/**
 * Switches the default database connection when a landlord context becomes active.
 *
 * When the landlord isolation mode is set to `IsolationMode::SEPARATE_DATABASE`, this
 * task resolves the target connection name from the landlord model's `databaseConfig()`
 * (if it implements `DatabaseAwareLandlordInterface`) or falls back to the
 * `tenancy.landlord.database.connection` config value. It then sets
 * `database.default` to the resolved connection and purges the connection from the
 * manager to ensure a fresh connection is used.
 *
 * A stack-based approach is used to support nested landlord contexts: each
 * `makeCurrent` call pushes the current default connection and the switched
 * connection onto separate stacks, and each `forgetCurrent` call pops them,
 * purging the tenant connection and restoring the prior default.
 *
 * When the isolation mode is `IsolationMode::SHARED_DATABASE`, no connection switch
 * occurs and `null` is recorded as the switched connection to keep the stacks aligned.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class SwitchLandlordDatabaseTask implements LandlordTaskInterface
{
    /**
     * Stack of default connection names captured before each context switch.
     *
     * Each entry corresponds to the value of `database.default` at the time
     * `makeCurrent` was called, allowing the original default to be restored
     * by `forgetCurrent` even when landlord contexts are nested.
     *
     * @var array<int, string>
     */
    private array $defaultConnectionStack = [];

    /**
     * Stack of connection names that were actually set by each `makeCurrent` call.
     *
     * A `null` entry indicates that no connection switch was performed for that
     * context level (either because the isolation mode is shared or because the
     * resolved connection was identical to the current default). This stack is
     * kept in sync with `$defaultConnectionStack`.
     *
     * @var array<int, null|string>
     */
    private array $switchedConnectionStack = [];

    /**
     * Create a new task instance.
     *
     * @param DatabaseManager $database The Laravel database manager used to purge connections
     *                                  and enforce a fresh connection after a context switch.
     */
    public function __construct(
        private readonly DatabaseManager $database,
    ) {}

    /**
     * Switch the default database connection to the landlord's dedicated connection.
     *
     * Records the current default connection for later restoration, then — if the
     * isolation mode is `SEPARATE_DATABASE` and a distinct connection name can be
     * resolved — updates `database.default` and purges the resolved connection from
     * the manager so the next query opens a fresh link. If no switch is needed, `null`
     * is pushed onto the switched-connection stack to keep the two stacks aligned.
     *
     * @param LandlordContext $landlordContext The landlord context that is becoming active.
     */
    public function makeCurrent(LandlordContext $landlordContext): void
    {
        $currentDefault = config('database.default', 'testing');

        if (!is_string($currentDefault) || $currentDefault === '') {
            $currentDefault = 'testing';
        }

        $this->defaultConnectionStack[] = $currentDefault;

        if ($this->isolationMode() === IsolationMode::SHARED_DATABASE) {
            $this->switchedConnectionStack[] = null;

            return;
        }

        $connection = $this->resolveConnectionName($landlordContext);

        if (!is_string($connection) || $connection === '' || $connection === $currentDefault) {
            $this->switchedConnectionStack[] = null;

            return;
        }

        config()->set('database.default', $connection);
        $this->database->purge($connection);
        $this->switchedConnectionStack[] = $connection;
    }

    /**
     * Restore the default database connection that was active before this context.
     *
     * Pops both stacks to retrieve the previous default and the connection that was
     * switched in. If a connection was switched, it is purged from the manager before
     * the original default is restored, ensuring no stale connection lingers.
     *
     * @param LandlordContext $landlordContext The landlord context that is being deactivated.
     */
    public function forgetCurrent(LandlordContext $landlordContext): void
    {
        $previousDefault = array_pop($this->defaultConnectionStack);
        $switchedConnection = array_pop($this->switchedConnectionStack);
        $currentDefault = config('database.default');

        if (is_string($switchedConnection) && $switchedConnection !== '' && is_string($currentDefault) && $currentDefault !== '') {
            $this->database->purge($currentDefault);
        }

        if (!is_string($previousDefault) || $previousDefault === '') {
            return;
        }

        config()->set('database.default', $previousDefault);
    }

    /**
     * Resolve the configured landlord database isolation mode.
     *
     * Reads `tenancy.landlord.isolation` from config and returns the corresponding
     * `IsolationMode` enum case. Defaults to `IsolationMode::SHARED_DATABASE` when
     * the value is missing or does not match a known case.
     */
    private function isolationMode(): IsolationMode
    {
        $mode = config('tenancy.landlord.isolation', IsolationMode::SHARED_DATABASE->value);

        if (!is_string($mode)) {
            return IsolationMode::SHARED_DATABASE;
        }

        return IsolationMode::tryFrom($mode) ?? IsolationMode::SHARED_DATABASE;
    }

    /**
     * Resolve the database connection name for the given landlord context.
     *
     * First attempts to read the connection from the landlord model's own
     * `databaseConfig()` array (available when the model implements
     * `DatabaseAwareLandlordInterface`). Falls back to the static
     * `tenancy.landlord.database.connection` config value when the model does
     * not implement the interface or does not specify a connection.
     *
     * @param  LandlordContext $landlordContext The landlord context to resolve a connection for.
     * @return null|string     The resolved connection name, or `null` if none can be determined.
     */
    private function resolveConnectionName(LandlordContext $landlordContext): ?string
    {
        $fallback = config('tenancy.landlord.database.connection');

        if (!is_string($fallback) || $fallback === '') {
            $fallback = null;
        }

        $landlord = $landlordContext->landlord;

        if (!$landlord instanceof DatabaseAwareLandlordInterface) {
            return $fallback;
        }

        $database = $landlord->databaseConfig();

        if (!is_array($database)) {
            return $fallback;
        }

        $connection = $database['connection'] ?? null;

        return is_string($connection) && $connection !== '' ? $connection : $fallback;
    }
}
