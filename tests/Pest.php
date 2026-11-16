<?php

declare(strict_types=1);

use Dakotajune\BlueprintPolicies\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

function lints(string $source): bool
{
    try {
        token_get_all($source, TOKEN_PARSE);

        return true;
    } catch (ParseError) {
        return false;
    }
}

function read(string $path): string
{
    return file_get_contents(base_path($path));
}

/**
 * Every file the package can touch, keyed by forward-slash path, for "nothing changed" comparisons.
 *
 * @return array<string, string>
 */
function snapshot(): array
{
    $files = [];
    $root = str_replace('\\', '/', base_path()) . '/';

    foreach (['app/Policies', 'app/Auth', 'app/Http/Controllers', 'app/Models', 'app/Providers'] as $directory) {
        if (! is_dir(base_path($directory))) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[substr(str_replace('\\', '/', $file->getPathname()), strlen($root))] = file_get_contents($file->getPathname());
            }
        }
    }

    ksort($files);

    return $files;
}
