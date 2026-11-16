<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintPolicies\Support;

/**
 * What this package changed, so `blueprint:erase` can undo exactly that and nothing else.
 *
 * policies:    path => [class, created]                 policy files (created = we made the file)
 * controllers: path => [class, uses]                    controllers that got tagged authorize statements
 * models:      path => [class, policy, uses]            models that got #[UsePolicy(...)]
 * providers:   path => [class, uses]                    providers that got tagged Gate::policy(...) statements
 */
final class Manifest
{
    /** @var array<string, array{class: string, created: bool}> */
    private array $policies = [];

    /** @var array<string, array{class: string, uses: array<int, string>}> */
    private array $controllers = [];

    /** @var array<string, array{class: string, policy: string, uses: array<int, string>}> */
    private array $models = [];

    /** @var array<string, array{class: string, uses: array<int, string>}> */
    private array $providers = [];

    public function recordPolicy(string $path, string $class, bool $created): void
    {
        $this->policies[$path] = ['class' => $class, 'created' => $created];
    }

    /** @param  array<int, string>  $uses */
    public function recordController(string $path, string $class, array $uses): void
    {
        $this->controllers[$path] = ['class' => $class, 'uses' => array_values(array_unique($uses))];
    }

    /** @param  array<int, string>  $uses */
    public function recordModel(string $path, string $class, string $policy, array $uses): void
    {
        $this->models[$path] = ['class' => $class, 'policy' => $policy, 'uses' => array_values(array_unique($uses))];
    }

    /** @param  array<int, string>  $uses */
    public function recordProvider(string $path, string $class, array $uses): void
    {
        $this->providers[$path] = ['class' => $class, 'uses' => array_values(array_unique($uses))];
    }

    /** @return array<string, array{class: string, created: bool}> */
    public function policies(): array
    {
        return $this->policies;
    }

    /** @return array<string, array{class: string, uses: array<int, string>}> */
    public function controllers(): array
    {
        return $this->controllers;
    }

    /** @return array<string, array{class: string, policy: string, uses: array<int, string>}> */
    public function models(): array
    {
        return $this->models;
    }

    /** @return array<string, array{class: string, uses: array<int, string>}> */
    public function providers(): array
    {
        return $this->providers;
    }

    public function policy(string $path): ?array
    {
        return $this->policies[$path] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->policies === [] && $this->controllers === [] && $this->models === [] && $this->providers === [];
    }

    public function toJson(): string
    {
        return json_encode([
            'policies' => $this->policies,
            'controllers' => $this->controllers,
            'models' => $this->models,
            'providers' => $this->providers,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    public static function fromJson(?string $json): self
    {
        $manifest = new self();
        $data = $json === null ? [] : json_decode($json, true);

        if (! is_array($data)) {
            return $manifest;
        }

        $manifest->policies = (array) ($data['policies'] ?? []);
        $manifest->controllers = (array) ($data['controllers'] ?? []);
        $manifest->models = (array) ($data['models'] ?? []);
        $manifest->providers = (array) ($data['providers'] ?? []);

        return $manifest;
    }
}
