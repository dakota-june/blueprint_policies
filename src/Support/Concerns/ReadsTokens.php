<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintPolicies\Support\Concerns;

use PhpToken;
use RuntimeException;

/**
 * Token / import helpers shared by the source editors. Pure PHP, no framework dependency.
 */
trait ReadsTokens
{
    /** @param  array<int, PhpToken>  $tokens */
    private function prevSignificant(array $tokens, int $from): ?int
    {
        for ($i = $from - 1; $i >= 0; $i--) {
            if (! $tokens[$i]->isIgnorable()) {
                return $i;
            }
        }

        return null;
    }

    /** @param  array<int, PhpToken>  $tokens */
    private function nextSignificant(array $tokens, int $from): ?int
    {
        for ($i = $from + 1, $n = count($tokens); $i < $n; $i++) {
            if (! $tokens[$i]->isIgnorable()) {
                return $i;
            }
        }

        return null;
    }

    /** @param  array<int, PhpToken>  $tokens */
    private function prevNonWhitespace(array $tokens, int $from): ?int
    {
        for ($i = $from - 1; $i >= 0; $i--) {
            if (! $tokens[$i]->is(T_WHITESPACE)) {
                return $i;
            }
        }

        return null;
    }

    private function namespaceOf(string $source): ?string
    {
        return preg_match('/^\s*namespace\s+([^;{\s]+)/m', $source, $m) ? $m[1] : null;
    }

    private function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    private function namespaceName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? '' : substr($fqcn, 0, $pos);
    }

    /** @param  array<int, array{0:int,1:int,2:string}>  $edits */
    private function apply(string $source, array $edits): string
    {
        usort($edits, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        foreach ($edits as [$offset, $length, $text]) {
            $source = substr_replace($source, $text, $offset, $length);
        }

        return $source;
    }

    /**
     * Top-level `use Foo\Bar [as Baz];` statements before the class.
     *
     * @param  array<int, PhpToken>  $tokens
     * @return array<string, string>  alias => fqcn
     */
    private function imports(array $tokens, int $classIdx): array
    {
        $map = [];

        foreach ($this->importStatements($tokens, $classIdx) as [$from, $to]) {
            $text = '';
            for ($i = $from + 1; $i < $to; $i++) {
                $text .= $tokens[$i]->text;
            }
            $text = trim($text);

            if (preg_match('/^(function|const)\s/i', $text) || str_contains($text, '{')) {
                continue; // function/const/group imports are not tracked
            }

            foreach (explode(',', $text) as $part) {
                if (preg_match('/^\s*\\\\?([\w\\\\]+)(?:\s+as\s+(\w+))?\s*$/i', $part, $m)) {
                    $fqcn = $m[1];
                    $alias = $m[2] ?? $this->shortName($fqcn);
                    $map[$alias] = $fqcn;
                }
            }
        }

        return $map;
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     * @return array<int, array{0:int,1:int}>  [useIdx, semicolonIdx]
     */
    private function importStatements(array $tokens, int $classIdx): array
    {
        $statements = [];

        for ($i = 0; $i < $classIdx; $i++) {
            if (! $tokens[$i]->is(T_USE)) {
                continue;
            }
            for ($j = $i + 1; $j < $classIdx; $j++) {
                if ($tokens[$j]->text === ';') {
                    $statements[] = [$i, $j];
                    $i = $j;
                    break;
                }
            }
        }

        return $statements;
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     * @param  array<int, string>  $imports
     * @return array{0:int,1:int,2:string}
     */
    private function importEdit(array $tokens, int $classIdx, array $imports): array
    {
        $lines = array_map(static fn (string $fqcn): string => "use {$fqcn};", $imports);
        $statements = $this->importStatements($tokens, $classIdx);

        if ($statements !== []) {
            $last = end($statements);
            $offset = $tokens[$last[1]]->pos + 1;

            return [$offset, 0, "\n" . implode("\n", $lines)];
        }

        for ($i = 0; $i < $classIdx; $i++) {
            if ($tokens[$i]->is(T_NAMESPACE)) {
                for ($j = $i + 1; $j < $classIdx; $j++) {
                    if ($tokens[$j]->text === ';') {
                        return [$tokens[$j]->pos + 1, 0, "\n\n" . implode("\n", $lines)];
                    }
                }
            }
        }

        throw new RuntimeException('Cannot add imports: no namespace statement found in the resource file.');
    }

    /** @param  array<int, PhpToken>  $tokens */
    private function findClass(array $tokens, string $name): ?int
    {
        foreach ($tokens as $i => $token) {
            if (! $token->is(T_CLASS)) {
                continue;
            }

            $prev = $this->prevSignificant($tokens, $i);
            if ($prev !== null && $tokens[$prev]->is([T_DOUBLE_COLON, T_NEW])) {
                continue; // Foo::class or `new class`
            }

            $next = $this->nextSignificant($tokens, $i);
            if ($next !== null && $tokens[$next]->is(T_STRING) && $tokens[$next]->text === $name) {
                return $i;
            }
        }

        return null;
    }

    /** Index of the `{` / `}` pair partner, or null. @param  array<int, PhpToken>  $tokens */
    private function matchBrace(array $tokens, int $open): ?int
    {
        $depth = 0;
        for ($i = $open, $n = count($tokens); $i < $n; $i++) {
            if ($tokens[$i]->text === '{' || $tokens[$i]->text === '${') {
                $depth++;
            } elseif ($tokens[$i]->text === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /** @param  array<int, PhpToken>  $tokens */
    private function matchParenTokens(array $tokens, int $open): ?int
    {
        $depth = 0;
        for ($i = $open, $n = count($tokens); $i < $n; $i++) {
            if ($tokens[$i]->text === '(') {
                $depth++;
            } elseif ($tokens[$i]->text === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /** Opening `#[` of the attribute group closed by the `]` at $close, or null if that `]` is not an attribute. @param  array<int, PhpToken>  $tokens */
    private function attributeOpenFor(array $tokens, int $close): ?int
    {
        $depth = 0;
        for ($k = $close; $k >= 0; $k--) {
            $t = $tokens[$k];
            if ($t->text === ']') {
                $depth++;
            } elseif ($t->is(T_ATTRIBUTE) || $t->text === '[') {
                $depth--;
                if ($depth === 0) {
                    return $t->is(T_ATTRIBUTE) ? $k : null;
                }
            }
        }

        return null;
    }
}
