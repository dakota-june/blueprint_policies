<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintPolicies\Support;

use Illuminate\Filesystem\Filesystem;

/** Reads / writes the `.blueprint_policies` manifest next to Blueprint's own `.blueprint` file. */
final class ManifestStore
{
    public function __construct(private readonly Filesystem $files) {}

    public function path(): string
    {
        return base_path((string) config('blueprint_policies.manifest', '.blueprint_policies'));
    }

    public function load(): Manifest
    {
        return Manifest::fromJson($this->files->exists($this->path()) ? $this->files->get($this->path()) : null);
    }

    public function save(Manifest $manifest): void
    {
        if ($manifest->isEmpty()) {
            $this->delete();

            return;
        }

        $this->files->put($this->path(), $manifest->toJson());
    }

    public function delete(): void
    {
        if ($this->files->exists($this->path())) {
            $this->files->delete($this->path());
        }
    }
}
