<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintPolicies\Support\Concerns;

/**
 * Turns `{{ use:Fqcn }}` placeholders into a usable class name and records the imports that must be added.
 * The using class must also use ReadsTokens and define a USE_PATTERN constant.
 */
trait ResolvesNames
{
    /**
     * Replaces `{{ use:Fqcn }}` placeholders with a usable name, registering an import when one is needed.
     *
     * @param  array<string, string>  $known  alias => fqcn (updated in place)
     * @param  array<int, string>  $newImports
     */
    protected function expand(string $text, ?string $namespace, array &$known, array &$newImports): string
    {
        // `use (&$known, &$newImports)` on purpose: a by-value capture would silently drop the recorded imports.
        return (string) preg_replace_callback(
            self::USE_PATTERN,
            function (array $m) use ($namespace, &$known, &$newImports): string {
                return $this->resolve(ltrim($m[1], '\\'), $namespace, $known, $newImports);
            },
            $text,
        );
    }

    /**
     * @param  array<string, string>  $known
     * @param  array<int, string>  $newImports
     */
    protected function resolve(string $fqcn, ?string $namespace, array &$known, array &$newImports): string
    {
        $short = $this->shortName($fqcn);

        if (($known[$short] ?? null) === $fqcn) {
            return $short;                                  // already imported (or declared here) under its own name
        }

        $alias = array_search($fqcn, $known, true);
        if ($alias !== false) {
            return (string) $alias;                         // imported under an alias
        }

        if (isset($known[$short])) {
            return '\\' . $fqcn;                            // short name taken by a different class
        }

        if ($this->namespaceName($fqcn) === (string) $namespace) {
            $known[$short] = $fqcn;                         // same namespace: no import, but the name is now spoken for

            return $short;
        }

        $known[$short] = $fqcn;
        $newImports[] = $fqcn;

        return $short;
    }
}
