<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintPolicies\Support;

use Blueprint\Blueprint;
use Illuminate\Filesystem\Filesystem;

/**
 * Safe, backed-up edits to Blueprint's `.blueprint` file (the record `blueprint:erase` works from).
 */
final class BlueprintFile
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly Blueprint $blueprint,
    ) {}

    public function path(): string
    {
        return base_path('.blueprint');
    }

    /**
     * Removes the given paths from the `created` list so erase will not delete them.
     * A copy of the original is written next to it first; nothing is changed if the backup cannot be made.
     *
     * @param  array<int, string>  $paths
     * @return string|null  The backup path when the file was modified, otherwise null.
     */
    public function protect(array $paths): ?string
    {
        if ($paths === [] || ! $this->files->exists($this->path())) {
            return null;
        }

        $contents = $this->files->get($this->path());
        $generated = $this->blueprint->parse($contents, false);

        $created = $generated['created'] ?? [];

        if (! is_array($created)) {
            return null;
        }

        $remaining = array_values(array_filter(
            $created,
            fn (mixed $entry): bool => ! in_array($this->pathOf($entry), $paths, true),
        ));

        if (count($remaining) === count($created)) {
            return null;
        }

        $backup = $this->backupPath();

        if (! $this->files->copy($this->path(), $backup)) {
            return null;
        }

        $generated['created'] = $remaining;
        $this->files->put($this->path(), $this->blueprint->dump($generated));

        return $backup;
    }

    /**
     * Blueprint's own generators record some files as [label, path] (e.g. ["Model", "app/Models/Post.php"]),
     * ours as plain path strings. The path is always the last element.
     */
    private function pathOf(mixed $entry): string
    {
        if (is_array($entry)) {
            $entry = end($entry);
        }

        return is_string($entry) ? $entry : '';
    }

    private function backupPath(): string
    {
        $base = $this->path() . '.bak-' . date('YmdHis');
        $path = $base;

        for ($i = 1; $this->files->exists($path); $i++) {
            $path = "{$base}-{$i}";
        }

        return $path;
    }
}
