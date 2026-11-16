<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintPolicies\Support;

/**
 * Reads the package config for one output target.
 *
 * The `laravel` target (the `policies:` section) reads the top-level keys of config/blueprint_policies.php.
 * The `nova` target (the `nova_policies:` section) reads the `nova` block first and falls back to the top-level
 * key when the block does not set it. A key that is present in the block wins even when its value is null or false,
 * so `'before' => false` under `nova` turns a global `before` off for Nova alone.
 */
final class TargetConfig
{
    public const LARAVEL = 'laravel';

    public const NOVA = 'nova';

    /** @param  string  $key  dot notation, e.g. "controllers.wire" */
    public static function get(string $target, string $key, mixed $default = null): mixed
    {
        $all = config('blueprint_policies', []);
        $all = is_array($all) ? $all : [];

        if ($target === self::NOVA && is_array($all['nova'] ?? null)) {
            [$found, $value] = self::lookup($all['nova'], $key);

            if ($found) {
                return $value;
            }
        }

        [$found, $value] = self::lookup($all, $key);

        return $found ? $value : $default;
    }

    /** @return array<string, string> rule term => PHP template */
    public static function checks(string $target): array
    {
        $checks = (array) config('blueprint_policies.checks', []);

        if ($target === self::NOVA) {
            $checks = array_merge($checks, (array) config('blueprint_policies.nova.checks', []));
        }

        return $checks;
    }

    /** @return array{0: bool, 1: mixed} */
    private static function lookup(array $array, string $key): array
    {
        $value = $array;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return [false, null];
            }

            $value = $value[$segment];
        }

        return [true, $value];
    }
}
