<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintPolicies\Listeners;

use Dakotajune\BlueprintPolicies\Support\BlueprintFile;
use Dakotajune\BlueprintPolicies\Support\ManifestStore;
use Dakotajune\BlueprintPolicies\Support\NoticeBag;
use Dakotajune\BlueprintPolicies\Support\PolicyClassEditor;
use Dakotajune\BlueprintPolicies\Support\WiringReverter;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Filesystem\Filesystem;

/**
 * `blueprint:erase` deletes every file in `.blueprint`'s `created` list and cannot undo edits to other files.
 * Just before it starts, this puts our changes back, using the manifest written at build time:
 *
 *  - tagged authorize statements are removed from controllers, Gate::policy() lines from the provider,
 *    #[UsePolicy] from models and `$policy` from Nova resources (each only where it names our policy);
 *  - policy files (web and Nova alike) lose their generated abilities; a policy that still holds your code, or that
 *    we did not create, is taken off erase's delete list (`.blueprint` is edited, backed up first) so it survives.
 */
final class CleanUpOnErase
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly PolicyClassEditor $policies,
        private readonly WiringReverter $reverter,
        private readonly ManifestStore $manifests,
        private readonly BlueprintFile $blueprintFile,
        private readonly NoticeBag $notices,
    ) {}

    public function handle(CommandStarting $event): void
    {
        if ($event->command !== 'blueprint:erase') {
            return;
        }

        $manifest = $this->manifests->load();

        if ($manifest->isEmpty()) {
            return;
        }

        foreach ($manifest->controllers() as $path => $meta) {
            $this->reverter->controller($path, $meta);
        }

        foreach ($manifest->providers() as $path => $meta) {
            $this->reverter->provider($path, $meta);
        }

        foreach ($manifest->models() as $path => $meta) {
            $this->reverter->model($path, $meta);
        }

        foreach ($manifest->resources() as $path => $meta) {
            $this->reverter->resource($path, $meta);
        }

        $protect = [];

        foreach ($manifest->policies() as $path => $meta) {
            if (! $this->files->exists(base_path($path))) {
                continue;
            }

            $original = $this->files->get(base_path($path));
            $stripped = $this->policies->stripGenerated($original, $meta['class']);

            if ($stripped !== $original) {
                $this->files->put(base_path($path), $stripped);
            }

            if (! $meta['created'] || $this->policies->hasDeveloperContent($stripped, $meta['class'])) {
                $protect[] = $path;
                $this->notices->add("{$path} still contains your code, so it was kept; only the generated abilities were removed.");
            }
        }

        $backup = $this->blueprintFile->protect($protect);

        if ($backup !== null) {
            $this->notices->add('Edited .blueprint so erase skips the files above. The original is saved as ' . basename($backup) . '.');
        }

        $this->manifests->delete();
    }
}
