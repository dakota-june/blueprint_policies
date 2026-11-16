<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintPolicies\Support;

/**
 * What this package changed, so `blueprint:erase` can undo exactly that and nothing else.
 *
 * policies:    path => [class, created, target]               policy files (created = we made the file)
 * controllers: path => [class, uses, owner]                   controllers that got tagged authorize statements
 * models:      path => [class, policy, uses, owner]           models that got #[UsePolicy(...)]
 * providers:   path => [class, uses, owners]                  providers that got tagged Gate::policy(...) statements
 * resources:   path => [class, policy, uses, owner]           Nova resources that got `public static $policy = ...`
 *
 * `owner` is the path of the policy file that caused the edit. A later build uses it to tell "this policy no
 * longer wants that edit" (undo it) from "the policy is not part of this draft" (leave the edit, and the record, alone).
 */
final class Manifest
{
    public const POLICIES = 'policies';

    public const CONTROLLERS = 'controllers';

    public const MODELS = 'models';

    public const PROVIDERS = 'providers';

    public const RESOURCES = 'resources';

    private const SECTIONS = [self::POLICIES, self::CONTROLLERS, self::MODELS, self::PROVIDERS, self::RESOURCES];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $data = [
        self::POLICIES => [],
        self::CONTROLLERS => [],
        self::MODELS => [],
        self::PROVIDERS => [],
        self::RESOURCES => [],
    ];

    public function recordPolicy(string $path, string $class, bool $created, string $target = TargetConfig::LARAVEL): void
    {
        $this->data[self::POLICIES][$path] = ['class' => $class, 'created' => $created, 'target' => $target];
    }

    /** @param  array<int, string>  $uses */
    public function recordController(string $path, string $class, array $uses, ?string $owner = null): void
    {
        $this->data[self::CONTROLLERS][$path] = ['class' => $class, 'uses' => array_values(array_unique($uses)), 'owner' => $owner];
    }

    /** @param  array<int, string>  $uses */
    public function recordModel(string $path, string $class, string $policy, array $uses, ?string $owner = null): void
    {
        $this->data[self::MODELS][$path] = ['class' => $class, 'policy' => $policy, 'uses' => array_values(array_unique($uses)), 'owner' => $owner];
    }

    /**
     * @param  array<int, string>  $uses
     * @param  array<int, string>  $owners  paths of the policy files whose Gate::policy() lines are in this provider
     */
    public function recordProvider(string $path, string $class, array $uses, array $owners = []): void
    {
        $this->data[self::PROVIDERS][$path] = ['class' => $class, 'uses' => array_values(array_unique($uses)), 'owners' => array_values(array_unique($owners))];
    }

    /** @param  array<int, string>  $uses */
    public function recordResource(string $path, string $class, string $policy, array $uses, ?string $owner = null): void
    {
        $this->data[self::RESOURCES][$path] = ['class' => $class, 'policy' => $policy, 'uses' => array_values(array_unique($uses)), 'owner' => $owner];
    }

    /** @return array<string, array<string, mixed>> */
    public function section(string $section): array
    {
        return $this->data[$section] ?? [];
    }

    public function has(string $section, string $path): bool
    {
        return isset($this->data[$section][$path]);
    }

    /** Copies an entry from an earlier manifest unchanged. */
    public function adopt(string $section, string $path, array $meta): void
    {
        if (isset($this->data[$section])) {
            $this->data[$section][$path] = $meta;
        }
    }

    /** @return array<string, array{class: string, created: bool, target?: string}> */
    public function policies(): array
    {
        return $this->data[self::POLICIES];
    }

    /** @return array<string, array{class: string, uses: array<int, string>, owner?: ?string}> */
    public function controllers(): array
    {
        return $this->data[self::CONTROLLERS];
    }

    /** @return array<string, array{class: string, policy: string, uses: array<int, string>, owner?: ?string}> */
    public function models(): array
    {
        return $this->data[self::MODELS];
    }

    /** @return array<string, array{class: string, uses: array<int, string>, owners?: array<int, string>}> */
    public function providers(): array
    {
        return $this->data[self::PROVIDERS];
    }

    /** @return array<string, array{class: string, policy: string, uses: array<int, string>, owner?: ?string}> */
    public function resources(): array
    {
        return $this->data[self::RESOURCES];
    }

    public function policy(string $path): ?array
    {
        return $this->data[self::POLICIES][$path] ?? null;
    }

    public function model(string $path): ?array
    {
        return $this->data[self::MODELS][$path] ?? null;
    }

    public function isEmpty(): bool
    {
        foreach ($this->data as $entries) {
            if ($entries !== []) {
                return false;
            }
        }

        return true;
    }

    public function toJson(): string
    {
        return json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    public static function fromJson(?string $json): self
    {
        $manifest = new self();
        $data = $json === null ? [] : json_decode($json, true);

        if (! is_array($data)) {
            return $manifest;
        }

        foreach (self::SECTIONS as $section) {
            $manifest->data[$section] = (array) ($data[$section] ?? []);
        }

        return $manifest;
    }
}
