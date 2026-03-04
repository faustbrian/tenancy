<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Tenancy\Tasks;

use Cline\Tenancy\Contracts\LandlordTaskInterface;
use Cline\Tenancy\LandlordContext;

use function array_pop;
use function config;
use function data_get;
use function is_array;
use function is_string;

/**
 * Maps landlord context payload values into Laravel config keys when a landlord context becomes active.
 *
 * Reads the `tenancy.landlord.config_mapping.mappings` configuration array, which defines
 * a set of `target_config_key => source_payload_key` pairs. On `makeCurrent`, each mapped
 * source value is read from the landlord's context payload using dot-notation via `data_get`
 * and written to the corresponding Laravel config key. The original config values are
 * captured in a snapshot stack so that `forgetCurrent` can restore them precisely, even
 * when landlord contexts are nested.
 *
 * ```php
 * // Example config mapping (config/tenancy.php):
 * 'landlord' => [
 *     'config_mapping' => [
 *         'mappings' => [
 *             'services.stripe.key' => 'stripe_key',
 *             'mail.from.address'   => 'mail_from',
 *         ],
 *     ],
 * ],
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class MapLandlordConfigTask implements LandlordTaskInterface
{
    /**
     * A stack of config snapshots captured before each context switch.
     *
     * Each entry maps target config keys to the values they held before the
     * corresponding `makeCurrent` call ran, allowing nested landlord contexts
     * to be unwound correctly.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $originalValues = [];

    /**
     * Apply landlord-specific config values from the context payload.
     *
     * Iterates over the configured mappings and sets each target Laravel config
     * key to the value extracted from the landlord's context payload. Source
     * values are resolved with dot-notation support via `data_get`. Keys whose
     * payload value is `null` are skipped, leaving the current config untouched.
     * The pre-switch values are pushed onto an internal snapshot stack so that
     * `forgetCurrent` can restore them in the correct order.
     *
     * @param LandlordContext $landlordContext The landlord context that is becoming active.
     */
    public function makeCurrent(LandlordContext $landlordContext): void
    {
        $mappings = $this->mappings();

        if ($mappings === []) {
            return;
        }

        $snapshot = [];
        $payload = $landlordContext->payload();

        foreach ($mappings as $targetConfigKey => $sourcePayloadKey) {
            $snapshot[$targetConfigKey] = config($targetConfigKey);

            $value = data_get($payload, $sourcePayloadKey);

            if ($value === null) {
                continue;
            }

            config()->set($targetConfigKey, $value);
        }

        $this->originalValues[] = $snapshot;
    }

    /**
     * Restore the config values that were overwritten in `makeCurrent`.
     *
     * Pops the most recent snapshot off the stack and writes each saved value
     * back to its config key. This preserves the correct state when landlord
     * contexts are nested, unwinding one level per call.
     *
     * @param LandlordContext $landlordContext The landlord context that is being deactivated.
     */
    public function forgetCurrent(LandlordContext $landlordContext): void
    {
        $snapshot = array_pop($this->originalValues);

        if (!is_array($snapshot)) {
            return;
        }

        foreach ($snapshot as $targetConfigKey => $originalValue) {
            config()->set($targetConfigKey, $originalValue);
        }
    }

    /**
     * Return the validated `target_config_key => source_payload_key` mappings from config.
     *
     * Reads `tenancy.landlord.config_mapping.mappings` and filters out any entries where
     * either the target key or the source key is not a non-empty string, ensuring that
     * only well-formed mappings are applied.
     *
     * @return array<string, string>
     */
    private function mappings(): array
    {
        $configuredMappings = config('tenancy.landlord.config_mapping.mappings', []);

        if (!is_array($configuredMappings)) {
            return [];
        }

        $mappings = [];

        foreach ($configuredMappings as $targetConfigKey => $sourcePayloadKey) {
            if (!is_string($targetConfigKey)) {
                continue;
            }

            if ($targetConfigKey === '') {
                continue;
            }

            if (!is_string($sourcePayloadKey)) {
                continue;
            }

            if ($sourcePayloadKey === '') {
                continue;
            }

            $mappings[$targetConfigKey] = $sourcePayloadKey;
        }

        return $mappings;
    }
}
