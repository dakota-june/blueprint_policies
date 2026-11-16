<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintPolicies\Support;

use Dakotajune\BlueprintPolicies\Support\Concerns\ReadsTokens;
use Dakotajune\BlueprintPolicies\Support\Concerns\RemovesImports;
use Dakotajune\BlueprintPolicies\Support\Concerns\ResolvesNames;
use PhpToken;

/**
 * Adds / removes `#[UsePolicy(PostPolicy::class)]` on an Eloquent model source string.
 *
 * Token-aware (modifiers, docblocks, other attributes, existing imports and short-name clashes) and idempotent.
 * A model that already names a different policy is left alone: that is the developer's decision.
 */
final class PolicyAttributeInjector
{
    use ReadsTokens;
    use RemovesImports;
    use ResolvesNames;

    public const ATTRIBUTE_FQCN = 'Illuminate\\Database\\Eloquent\\Attributes\\UsePolicy';

    public const USE_PATTERN = '/\{\{ use:([A-Za-z_][A-Za-z0-9_\\\\]*) \}\}/';

    public function inject(string $source, string $modelClass, string $policyFqcn): InjectionResult
    {
        $policyFqcn = ltrim($policyFqcn, '\\');
        $tokens = PhpToken::tokenize($source);
        $classIdx = $this->findClass($tokens, $modelClass);

        if ($classIdx === null) {
            return new InjectionResult($source, InjectionResult::CLASS_NOT_FOUND);
        }

        $declStart = $this->declarationStart($tokens, $classIdx);
        $existing = $this->existing($source, $tokens, $classIdx, $declStart);

        if ($existing !== null) {
            return new InjectionResult(
                $source,
                $this->matches($existing[2], $policyFqcn, $source, $tokens, $classIdx) ? InjectionResult::UNCHANGED : InjectionResult::CONFLICT,
            );
        }

        $namespace = $this->namespaceOf($source);
        $known = $this->imports($tokens, $classIdx);
        $known += [$modelClass => ltrim(($namespace !== null ? $namespace . '\\' : '') . $modelClass, '\\')];
        $newImports = [];

        $attribute = $this->expand(
            '#[{{ use:' . self::ATTRIBUTE_FQCN . ' }}({{ use:' . $policyFqcn . ' }}::class)]',
            $namespace,
            $known,
            $newImports,
        );

        $pos = $tokens[$declStart]->pos;
        $edits = [[$pos, 0, $attribute . "\n" . $this->indentAt($source, $pos)]];

        if ($newImports !== []) {
            $edits[] = $this->importEdit($tokens, $classIdx, array_values(array_unique($newImports)));
        }

        return new InjectionResult($this->apply($source, $edits), InjectionResult::INSERTED, [self::ATTRIBUTE_FQCN, $policyFqcn]);
    }

    /**
     * Reverse of inject(). Only removes an attribute that names this policy.
     *
     * @param  array<int, string>  $uses
     */
    public function remove(string $source, string $modelClass, string $policyFqcn, array $uses = []): string
    {
        $policyFqcn = ltrim($policyFqcn, '\\');
        $tokens = PhpToken::tokenize($source);
        $classIdx = $this->findClass($tokens, $modelClass);

        if ($classIdx === null) {
            return $source;
        }

        $existing = $this->existing($source, $tokens, $classIdx, $this->declarationStart($tokens, $classIdx));

        if ($existing === null || ! $this->matches($existing[2], $policyFqcn, $source, $tokens, $classIdx)) {
            return $source;
        }

        [$start, $length] = $existing;
        $end = $start + $length;

        while (isset($source[$end]) && ($source[$end] === ' ' || $source[$end] === "\t")) {
            $end++;
        }

        if (isset($source[$end]) && $source[$end] === "\n") {
            $end++;
        }

        return $this->removeImportsIfUnused(substr_replace($source, '', $start, $end - $start), $uses);
    }

    /**
     * The UsePolicy attribute group sitting in front of the class declaration, if any.
     *
     * @param  array<int, PhpToken>  $tokens
     * @return array{0:int,1:int,2:string}|null  offset, length, the class named in the attribute
     */
    private function existing(string $source, array $tokens, int $classIdx, int $declStart): ?array
    {
        $names = ['UsePolicy'];

        foreach ($this->imports($tokens, $classIdx) as $alias => $fqcn) {
            if ($fqcn === self::ATTRIBUTE_FQCN) {
                $names[] = (string) $alias;
            }
        }

        $before = substr($source, 0, $tokens[$declStart]->pos);
        $from = max((int) strrpos($before, ';'), 0);
        $region = substr($before, $from);
        $pattern = '/#\[\s*(?:\\\\?(?:[A-Za-z_]\w*\\\\)*)?(?:' . implode('|', array_map(static fn (string $n): string => preg_quote($n, '/'), array_unique($names)))
            . ')\s*\(\s*(\\\\?[\w\\\\]+)::class\s*\)\s*\]/';

        if (! preg_match($pattern, $region, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return [$from + $m[0][1], strlen($m[0][0]), ltrim($m[1][0], '\\')];
    }

    /**
     * Does the class named in the attribute (as written) resolve to this policy? A short name is resolved
     * through the file's imports, then its own namespace, so another PostPolicy elsewhere does not match.
     *
     * @param  array<int, PhpToken>  $tokens
     */
    private function matches(string $name, string $fqcn, string $source, array $tokens, int $classIdx): bool
    {
        if (str_contains($name, '\\')) {
            return $name === $fqcn;
        }

        $imports = $this->imports($tokens, $classIdx);
        $namespace = $this->namespaceOf($source);
        $resolved = $imports[$name] ?? ltrim(($namespace !== null ? $namespace . '\\' : '') . $name, '\\');

        return $resolved === $fqcn;
    }

    /** @param  array<int, PhpToken>  $tokens */
    private function declarationStart(array $tokens, int $classIdx): int
    {
        $start = $classIdx;

        while (($prev = $this->prevSignificant($tokens, $start)) !== null
            && $tokens[$prev]->is([T_FINAL, T_ABSTRACT, T_READONLY])) {
            $start = $prev;
        }

        return $start;
    }

    private function indentAt(string $source, int $pos): string
    {
        $lineStart = strrpos(substr($source, 0, $pos), "\n");
        $line = substr($source, $lineStart === false ? 0 : $lineStart + 1, $pos - ($lineStart === false ? 0 : $lineStart + 1));

        return trim($line) === '' ? $line : '';
    }
}
