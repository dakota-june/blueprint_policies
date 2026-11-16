<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintPolicies\Support;

use Dakotajune\BlueprintPolicies\Support\Concerns\ReadsTokens;
use Dakotajune\BlueprintPolicies\Support\Concerns\RemovesImports;
use Dakotajune\BlueprintPolicies\Support\Concerns\ResolvesNames;
use PhpToken;

/**
 * Puts tagged one-line statements at the top of a method body and takes them out again.
 *
 *     Gate::authorize('update', $post); // @generated blueprint_policies
 *
 * Only lines carrying the tag are ever touched, so a method's own code is left alone. Each run removes our
 * lines and writes the current set, which makes the edit idempotent and lets removed abilities disappear.
 * Statements may contain `{{ use:Fqcn }}` placeholders, resolved to a short name plus an import (or a
 * leading-backslash name when the short name is taken).
 */
final class StatementEditor
{
    use ReadsTokens;
    use RemovesImports;
    use ResolvesNames;

    public const MARKER = '@generated blueprint_policies';

    public const USE_PATTERN = '/\{\{ use:([A-Za-z_][A-Za-z0-9_\\\\]*) \}\}/';

    /** A whole line that ends in a statement followed by our tag. */
    private const TAG_LINE = '~^[ \t]*\S[^\n]*;[ \t]*// @generated blueprint_policies[ \t]*\n?~m';

    /**
     * @param  array<int, string>  $statements  without indentation or tag
     * @param  string|null  $requiresVariable  skip the method when its signature has no such parameter (e.g. "post")
     * @param  string|null  $skipWhenBodyMatches  regex; when the method's own code matches, do not add (and drop ours)
     */
    public function insert(
        string $source,
        string $class,
        string $method,
        array $statements,
        ?string $requiresVariable = null,
        ?string $skipWhenBodyMatches = null,
    ): EditResult {
        $tokens = PhpToken::tokenize($source);
        $classIdx = $this->findClass($tokens, $class);

        if ($classIdx === null) {
            return new EditResult($source, false, ["Class [{$class}] was not found; no statements were added to {$method}()."]);
        }

        $located = $this->locate($tokens, $classIdx, $method);

        if ($located === null) {
            return new EditResult($source, false, ["{$class}::{$method}() was not found, so no statements were added to it."]);
        }

        [$functionIdx, $paramsOpen, $paramsClose, $braceOpen, $braceClose] = $located;

        $bodyStart = $tokens[$braceOpen]->pos + 1;
        $body = substr($source, $bodyStart, $tokens[$braceClose]->pos - $bodyStart);
        $clean = (string) preg_replace(self::TAG_LINE, '', $body);

        $methodIndent = $this->lineIndent($source, $tokens[$functionIdx]->pos);
        $notices = [];
        $lines = '';
        $edits = [];

        if ($requiresVariable !== null
            && ! preg_match('/\$' . preg_quote($requiresVariable, '/') . '\b/', substr($source, $tokens[$paramsOpen]->pos, $tokens[$paramsClose]->pos - $tokens[$paramsOpen]->pos))) {
            $notices[] = "{$class}::{$method}() has no \${$requiresVariable} parameter, so no check was added to it.";
        } elseif ($skipWhenBodyMatches !== null && preg_match($skipWhenBodyMatches, $clean)) {
            $notices[] = "{$class}::{$method}() already checks authorization itself, so no check was added to it.";
        } else {
            $namespace = $this->namespaceOf($source);
            $known = $this->imports($tokens, $classIdx);
            $known += [$class => ltrim(($namespace !== null ? $namespace . '\\' : '') . $class, '\\')];
            $newImports = [];

            foreach ($statements as $statement) {
                $lines .= $methodIndent . '    ' . $this->expand($statement, $namespace, $known, $newImports) . ' // ' . self::MARKER . "\n";
            }

            if ($newImports !== []) {
                $edits[] = $this->importEdit($tokens, $classIdx, array_values(array_unique($newImports)));
            }
        }

        $newBody = $lines === ''
            ? $clean
            : (str_starts_with($clean, "\n") ? "\n" . $lines . substr($clean, 1) : "\n" . $lines . $methodIndent);

        $edits[] = [$bodyStart, strlen($body), $newBody];
        $result = $this->apply($source, $edits);

        return new EditResult($result, $result !== $source, $notices);
    }

    /**
     * Removes every tagged statement, then the imports they needed if nothing else uses them.
     *
     * @param  array<int, string>  $uses  Class names the statements imported
     */
    public function strip(string $source, array $uses = []): string
    {
        $stripped = (string) preg_replace(self::TAG_LINE, '', $source);

        return $stripped === $source ? $source : $this->removeImportsIfUnused($stripped, $uses);
    }

    /** True when the source still has tagged statements in it. */
    public function hasTagged(string $source): bool
    {
        return (bool) preg_match(self::TAG_LINE, $source);
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     * @return array{0:int,1:int,2:int,3:int,4:int}|null  function, params open, params close, body open, body close
     */
    private function locate(array $tokens, int $classIdx, string $method): ?array
    {
        $classOpen = null;

        for ($i = $classIdx; $i < count($tokens); $i++) {
            if ($tokens[$i]->text === '{') {
                $classOpen = $i;
                break;
            }
        }

        $classClose = $classOpen === null ? null : $this->matchBrace($tokens, $classOpen);

        if ($classClose === null) {
            return null;
        }

        for ($i = $classOpen; $i < $classClose; $i++) {
            if (! $tokens[$i]->is(T_FUNCTION)) {
                continue;
            }

            $name = $this->nextSignificant($tokens, $i);

            if ($name === null || ! $tokens[$name]->is(T_STRING) || $tokens[$name]->text !== $method) {
                continue;
            }

            $paramsOpen = $this->nextSignificant($tokens, $name);
            $paramsClose = $paramsOpen === null ? null : $this->matchParenTokens($tokens, $paramsOpen);

            if ($paramsClose === null) {
                return null;
            }

            for ($j = $paramsClose + 1; $j < $classClose; $j++) {
                if ($tokens[$j]->text === ';') {
                    return null;                        // abstract / interface method: no body
                }

                if ($tokens[$j]->text === '{') {
                    $braceClose = $this->matchBrace($tokens, $j);

                    return $braceClose === null ? null : [$i, $paramsOpen, $paramsClose, $j, $braceClose];
                }
            }

            return null;
        }

        return null;
    }

    /** Leading whitespace of the line that contains $offset. */
    private function lineIndent(string $source, int $offset): string
    {
        $lineStart = strrpos(substr($source, 0, $offset), "\n");
        $line = substr($source, $lineStart === false ? 0 : $lineStart + 1, $offset - ($lineStart === false ? 0 : $lineStart + 1));

        return trim($line) === '' ? $line : '    ';
    }
}
