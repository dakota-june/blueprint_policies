<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintPolicies\Support;

use Illuminate\Filesystem\Filesystem;

/**
 * Takes back edits this package made outside the policy files: tagged statements in controllers and providers,
 * #[UsePolicy] on models and `$policy` on Nova resources. Used by `blueprint:erase`, and by a build when a policy
 * no longer wants one of those edits (`controller: false`, a changed registration mode, a moved resource ...).
 */
final class WiringReverter
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly StatementEditor $statements,
        private readonly PolicyAttributeInjector $attributes,
        private readonly ResourcePolicyInjector $resources,
    ) {}

    /** @param  array{uses?: array<int, string>}  $meta */
    public function controller(string $path, array $meta): void
    {
        $this->statementsIn($path, (array) ($meta['uses'] ?? []));
    }

    /** @param  array{uses?: array<int, string>}  $meta */
    public function provider(string $path, array $meta): void
    {
        $this->statementsIn($path, (array) ($meta['uses'] ?? []));
    }

    /** @param  array{class: string, policy: string, uses?: array<int, string>}  $meta */
    public function model(string $path, array $meta): void
    {
        $this->edit($path, fn (string $source): string => $this->attributes->remove($source, $meta['class'], $meta['policy'], (array) ($meta['uses'] ?? [])));
    }

    /** @param  array{class: string, uses?: array<int, string>}  $meta */
    public function resource(string $path, array $meta): void
    {
        $this->edit($path, fn (string $source): string => $this->resources->remove($source, $meta['class'], (array) ($meta['uses'] ?? [])));
    }

    /** @param  array<int, string>  $uses */
    private function statementsIn(string $path, array $uses): void
    {
        $this->edit($path, fn (string $source): string => $this->statements->removeUnusedImports($this->statements->strip($source, $uses), $uses));
    }

    /** @param  callable(string): string  $change */
    private function edit(string $path, callable $change): void
    {
        $absolute = base_path($path);

        if (! $this->files->exists($absolute)) {
            return;
        }

        $source = $this->files->get($absolute);
        $changed = $change($source);

        if ($changed !== $source) {
            $this->files->put($absolute, $changed);
        }
    }
}
