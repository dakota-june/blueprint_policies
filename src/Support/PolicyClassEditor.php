<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintPolicies\Support;

use Dakotajune\BlueprintPolicies\Support\Concerns\ReadsTokens;
use Dakotajune\BlueprintPolicies\Support\Concerns\ResolvesNames;
use PhpToken;

/**
 * Edits a Nova policy file in place under one strict rule:
 *
 *   The generator owns exactly the properties and methods tagged `@generated blueprint_policies`.
 *   Nothing else is ever modified or removed.
 *
 * On update():
 *  - tagged member that is still wanted        -> rewritten in place
 *  - tagged member that is no longer wanted    -> deleted (the generator owns it)
 *  - wanted member that is missing             -> inserted (properties first, methods after the generated ones)
 *  - untagged member with a wanted name        -> the developer's; left alone and reported as a notice
 *  - imports are only ever added; developer members, comments, traits and constants are untouched
 *
 * Remove the tag from a member and it becomes the developer's.
 *
 * Member sources may contain `{{ use:Fully\Qualified\Name }}` placeholders. They become the short name when the
 * class can be imported (or lives in the file's namespace) and the fully qualified name when the short name is
 * already taken by something else, so generated code never silently refers to the wrong class.
 */
final class PolicyClassEditor
{
    use ReadsTokens;
    use ResolvesNames;

    public const MARKER = '@generated blueprint_policies';

    public const USE_PATTERN = '/\{\{ use:([A-Za-z_][A-Za-z0-9_\\\\]*) \}\}/';

    private const MARKER_PATTERN = '/@generated\s+blueprint_policies\b/';

    /**
     * @param  array<string, string>  $properties  property name (no "$") => source of the whole member
     * @param  array<string, string>  $methods  method name => source of the whole member
     * @param  array<int, string>  $imports  extra class names that must be importable (add-only), e.g. the base class
     */
    public function update(
        string $source,
        string $class,
        array $properties,
        array $methods,
        array $imports = [],
    ): EditResult {
        $tokens = PhpToken::tokenize($source);
        $classIdx = $this->findClass($tokens, $class);
        $scan = $classIdx === null ? null : $this->scan($tokens, $source, $classIdx);

        if ($classIdx === null || $scan === null) {
            return new EditResult($source, false, ["Class [{$class}] was not found in the policy file; it was left untouched."]);
        }

        $notices = [];
        $namespace = $this->namespaceOf($source);
        $known = $this->imports($tokens, $classIdx);                   // alias => fqcn
        $known += [$class => ltrim(($namespace !== null ? $namespace . '\\' : '') . $class, '\\')];
        $newImports = [];

        // -- Names the class must be able to import (base class, ...) come first so nothing else takes them ---
        foreach ($imports as $import) {
            $import = ltrim($import, '\\');
            $short = $this->shortName($import);

            if (in_array($import, $known, true) || $this->namespaceName($import) === (string) $namespace) {
                continue;
            }

            if (isset($known[$short])) {
                $notices[] = "{$class}: [{$import}] is needed but [{$short}] is already used for [{$known[$short]}]; "
                    . 'the import was skipped, so check the generated code.';

                continue;
            }

            $known[$short] = $import;
            $newImports[] = $import;
        }

        // Plain loops, not array_map(fn …): arrow functions capture by value and could not record the imports.
        foreach ($properties as $name => $text) {
            $properties[$name] = $this->expand($text, $namespace, $known, $newImports);
        }
        foreach ($methods as $name => $text) {
            $methods[$name] = $this->expand($text, $namespace, $known, $newImports);
        }

        $wanted = [];
        foreach ($properties as $name => $text) {
            $wanted['property:' . $name] = $text;
        }
        foreach ($methods as $name => $text) {
            $wanted['method:' . strtolower($name)] = $text;
        }

        // -- Existing members -----------------------------------------------------------------------------
        $edits = [];
        $handled = [];
        $deletions = [];
        $remaining = [];
        $lastGeneratedProperty = null;
        $lastGeneratedMethod = null;

        foreach ($scan['members'] as $member) {
            $key = $this->keyOf($member);
            $text = $wanted[$key] ?? null;

            if ($member['generated']) {
                if ($text !== null) {
                    $edits[] = [$member['start'], $member['end'] - $member['start'], $text];
                    $handled[$key] = true;
                    $remaining[] = $member;
                    if ($member['kind'] === 'property') {
                        $lastGeneratedProperty = $member;
                    } else {
                        $lastGeneratedMethod = $member;
                    }
                } else {
                    $deletions[] = $this->deletionRange($source, $member['start'], $member['end']);
                }

                continue;
            }

            if ($text !== null) {
                $handled[$key] = true;
                $label = $member['kind'] === 'property' ? "{$class}::\${$member['name']}" : "{$class}::{$member['name']}()";
                $notices[] = "{$label} already exists without the " . self::MARKER . ' tag, so it belongs to you and was left untouched.';
            }

            $remaining[] = $member;
        }

        foreach ($this->mergeRanges($deletions, $source) as [$from, $to]) {
            $edits[] = [$from, $to - $from, ''];
        }

        // -- Missing members --------------------------------------------------------------------------------
        $missingProperties = [];
        foreach ($properties as $name => $text) {
            if (! isset($handled['property:' . $name])) {
                $missingProperties[] = $text;
            }
        }

        $missingMethods = [];
        foreach ($methods as $name => $text) {
            if (! isset($handled['method:' . strtolower($name)])) {
                $missingMethods[] = $text;
            }
        }

        if ($missingProperties !== [] || $missingMethods !== []) {
            foreach ($this->insertions($source, $tokens, $scan, $remaining, $lastGeneratedProperty, $lastGeneratedMethod, $missingProperties, $missingMethods) as $edit) {
                $edits[] = $edit;
            }
        }

        // -- Imports (add-only) -------------------------------------------------------------------------------
        if ($newImports !== []) {
            sort($newImports);
            $edits[] = $this->importEdit($tokens, $classIdx, array_values(array_unique($newImports)));
        }

        $result = $this->apply($source, $edits);

        return new EditResult($result, $result !== $source, $notices);
    }

    /** Removes every generated member; developer members, imports and everything else stay exactly as they are. */
    public function stripGenerated(string $source, string $class): string
    {
        $tokens = PhpToken::tokenize($source);
        $classIdx = $this->findClass($tokens, $class);
        $scan = $classIdx === null ? null : $this->scan($tokens, $source, $classIdx);

        if ($scan === null) {
            return $source;
        }

        $ranges = [];
        foreach ($scan['members'] as $member) {
            if ($member['generated']) {
                $ranges[] = $this->deletionRange($source, $member['start'], $member['end']);
            }
        }

        return $this->apply($source, array_map(
            static fn (array $r): array => [$r[0], $r[1] - $r[0], ''],
            $this->mergeRanges($ranges, $source),
        ));
    }

    /**
     * True when the class body holds anything other than generated members and, when $seedHash is given, a
     * `fields()` method that is still exactly what was first written.
     */
    public function hasDeveloperContent(string $source, string $class, ?string $seedHash = null): bool
    {
        $stripped = $this->stripGenerated($source, $class);
        $tokens = PhpToken::tokenize($stripped);
        $classIdx = $this->findClass($tokens, $class);
        $scan = $classIdx === null ? null : $this->scan($tokens, $stripped, $classIdx);

        if ($scan === null) {
            return false;
        }

        if ($seedHash !== null) {
            $ranges = [];
            foreach ($scan['members'] as $member) {
                if ($member['kind'] === 'method' && strtolower($member['name']) === 'fields'
                    && $this->hash(substr($stripped, $member['start'], $member['end'] - $member['start'])) === $seedHash) {
                    $ranges[] = $this->deletionRange($stripped, $member['start'], $member['end']);
                }
            }

            $stripped = $this->apply($stripped, array_map(
                static fn (array $r): array => [$r[0], $r[1] - $r[0], ''],
                $this->mergeRanges($ranges, $stripped),
            ));

            $tokens = PhpToken::tokenize($stripped);
            $classIdx = $this->findClass($tokens, $class);
            $scan = $classIdx === null ? null : $this->scan($tokens, $stripped, $classIdx);

            if ($scan === null) {
                return false;
            }
        }

        $from = $tokens[$scan['open']]->pos + 1;
        $to = $tokens[$scan['close']]->pos;

        return trim(substr($stripped, $from, $to - $from)) !== '';
    }

    /** Whitespace-insensitive hash of one method, or null when it does not exist. Used to spot an untouched seed. */
    public function methodHash(string $source, string $class, string $method): ?string
    {
        $tokens = PhpToken::tokenize($source);
        $classIdx = $this->findClass($tokens, $class);
        $scan = $classIdx === null ? null : $this->scan($tokens, $source, $classIdx);

        foreach ($scan['members'] ?? [] as $member) {
            if ($member['kind'] === 'method' && strtolower($member['name']) === strtolower($method)) {
                return $this->hash(substr($source, $member['start'], $member['end'] - $member['start']));
            }
        }

        return null;
    }

    // =====================================================================================================
    // Scanning
    // =====================================================================================================

    /**
     * @param  array<int, PhpToken>  $tokens
     * @return array{open:int, close:int, members: array<int, array{kind:string,name:string,start:int,end:int,generated:bool}>}|null
     */
    private function scan(array $tokens, string $source, int $classIdx): ?array
    {
        $open = null;
        for ($i = $classIdx, $n = count($tokens); $i < $n; $i++) {
            if ($tokens[$i]->text === '{') {
                $open = $i;
                break;
            }
        }

        $close = $open === null ? null : $this->matchBrace($tokens, $open);
        if ($open === null || $close === null) {
            return null;
        }

        $members = [];
        $depth = 0;

        for ($i = $open + 1; $i < $close; $i++) {
            $text = $tokens[$i]->text;

            if ($text === '{' || $text === '${') {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
            } elseif ($depth === 0 && $tokens[$i]->is(T_FUNCTION)) {
                $member = $this->method($tokens, $source, $i, $close);
                if ($member !== null) {
                    $members[] = $member;
                    $i = $member['endIdx'];
                }
            } elseif ($depth === 0 && $tokens[$i]->is(T_VARIABLE)) {
                $member = $this->property($tokens, $source, $i, $close);
                if ($member !== null) {
                    $members[] = $member;
                    $i = $member['endIdx'];
                }
            }
        }

        return ['open' => $open, 'close' => $close, 'members' => $members];
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     * @return array{kind:string,name:string,start:int,end:int,generated:bool,endIdx:int}|null
     */
    private function method(array $tokens, string $source, int $functionIdx, int $classClose): ?array
    {
        $nameIdx = $this->nextSignificant($tokens, $functionIdx);
        if ($nameIdx !== null && $tokens[$nameIdx]->text === '&') {
            $nameIdx = $this->nextSignificant($tokens, $nameIdx);
        }

        $parenOpen = $nameIdx === null ? null : $this->nextSignificant($tokens, $nameIdx);
        if ($nameIdx === null || $parenOpen === null || $tokens[$parenOpen]->text !== '(') {
            return null;                                   // closure / arrow fn, not a method
        }

        $parenClose = $this->matchParenTokens($tokens, $parenOpen);
        if ($parenClose === null) {
            return null;
        }

        $endIdx = null;
        for ($k = $parenClose + 1; $k < $classClose; $k++) {
            if ($tokens[$k]->text === '{') {
                $endIdx = $this->matchBrace($tokens, $k);
                break;
            }
            if ($tokens[$k]->text === ';') {
                $endIdx = $k;
                break;
            }
        }
        if ($endIdx === null) {
            return null;
        }

        $start = $functionIdx;
        while (($p = $this->prevSignificant($tokens, $start)) !== null
            && $tokens[$p]->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT])) {
            $start = $p;
        }

        return $this->describe('method', $tokens[$nameIdx]->text, $tokens, $source, $start, $endIdx);
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     * @return array{kind:string,name:string,start:int,end:int,generated:bool,endIdx:int}|null
     */
    private function property(array $tokens, string $source, int $variableIdx, int $classClose): ?array
    {
        // A property statement may declare several variables; only the first starts a member.
        $endIdx = null;
        $nesting = 0;
        for ($k = $variableIdx + 1; $k < $classClose; $k++) {
            $t = $tokens[$k]->text;
            if ($t === '(' || $t === '[' || $t === '{' || $t === '${') {
                $nesting++;
            } elseif ($t === ')' || $t === ']' || $t === '}') {
                $nesting--;
            } elseif ($t === ';' && $nesting <= 0) {
                $endIdx = $k;
                break;
            }
        }
        if ($endIdx === null) {
            return null;
        }

        // Walk back over modifiers and the type declaration, if any.
        $start = $variableIdx;
        while (($p = $this->prevSignificant($tokens, $start)) !== null && $this->isPropertyPrefix($tokens[$p])) {
            $start = $p;
        }

        return $this->describe('property', ltrim($tokens[$variableIdx]->text, '$'), $tokens, $source, $start, $endIdx);
    }

    private function isPropertyPrefix(PhpToken $token): bool
    {
        return $token->is([
            T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_READONLY, T_VAR,
            T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_ARRAY,
        ]) || in_array($token->text, ['?', '|', '&'], true);
    }

    /**
     * Shared tail of method()/property(): extends a member upwards over attributes and docblocks and decides
     * whether it carries the ownership tag.
     *
     * @param  array<int, PhpToken>  $tokens
     * @return array{kind:string,name:string,start:int,end:int,generated:bool,endIdx:int}
     */
    private function describe(string $kind, string $name, array $tokens, string $source, int $start, int $endIdx): array
    {
        $docs = '';
        while (($p = $this->prevNonWhitespace($tokens, $start)) !== null) {
            if ($tokens[$p]->is(T_DOC_COMMENT)) {
                $docs .= $tokens[$p]->text;
                $start = $p;
            } elseif ($tokens[$p]->text === ']' && ($open = $this->attributeOpenFor($tokens, $p)) !== null) {
                $start = $open;
            } else {
                break;
            }
        }

        $pos = $tokens[$start]->pos;
        $lineStart = strrpos(substr($source, 0, $pos), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        if (trim(substr($source, $lineStart, $pos - $lineStart)) === '') {
            $pos = $lineStart;
        }

        return [
            'kind' => $kind,
            'name' => $name,
            'start' => $pos,
            'end' => $tokens[$endIdx]->pos + strlen($tokens[$endIdx]->text),
            'generated' => (bool) preg_match(self::MARKER_PATTERN, $docs),
            'endIdx' => $endIdx,
        ];
    }

    /** @param  array{kind:string,name:string}  $member */
    private function keyOf(array $member): string
    {
        return $member['kind'] === 'method' ? 'method:' . strtolower($member['name']) : 'property:' . $member['name'];
    }

    // =====================================================================================================
    // Edit planning
    // =====================================================================================================

    /**
     * @param  array<int, PhpToken>  $tokens
     * @param  array{open:int, close:int, members: array}  $scan
     * @param  array<int, array{kind:string,start:int,end:int}>  $remaining
     * @param  array<int, string>  $properties
     * @param  array<int, string>  $methods
     * @return array<int, array{0:int,1:int,2:string}>
     */
    private function insertions(
        string $source,
        array $tokens,
        array $scan,
        array $remaining,
        ?array $lastGeneratedProperty,
        ?array $lastGeneratedMethod,
        array $properties,
        array $methods,
    ): array {
        $open = $tokens[$scan['open']]->pos;
        $close = $tokens[$scan['close']]->pos;
        $body = substr($source, $open + 1, $close - $open - 1);

        if ($scan['members'] === [] && trim($body) === '') {
            return [[$open + 1, strlen($body), "\n" . implode("\n\n", [...$properties, ...$methods]) . "\n"]];
        }

        /** @var array<int, string> $inserts offset => text, built in order so same-offset edits stay in order */
        $inserts = [];

        if ($properties !== []) {
            if ($lastGeneratedProperty !== null) {
                $inserts[$lastGeneratedProperty['end']] = ($inserts[$lastGeneratedProperty['end']] ?? '') . "\n\n" . implode("\n\n", $properties);
            } else {
                $inserts[$open + 1] = ($inserts[$open + 1] ?? '') . "\n" . implode("\n\n", $properties) . "\n";
            }
        }

        if ($methods !== []) {
            $anchor = $lastGeneratedMethod
                ?? $this->lastOfKind($remaining, 'method')
                ?? ($remaining === [] ? null : end($remaining));

            $offset = $anchor !== null ? $anchor['end'] : $open + 1 + strlen(rtrim($body));
            $inserts[$offset] = ($inserts[$offset] ?? '') . "\n\n" . implode("\n\n", $methods);
        }

        $edits = [];
        foreach ($inserts as $offset => $text) {
            $edits[] = [$offset, 0, $text];
        }

        return $edits;
    }

    /**
     * @param  array<int, array{kind:string}>  $members
     * @return array<string, mixed>|null
     */
    private function lastOfKind(array $members, string $kind): ?array
    {
        $found = null;
        foreach ($members as $member) {
            if ($member['kind'] === $kind) {
                $found = $member;
            }
        }

        return $found;
    }

    /**
     * Whole lines of a member plus the surrounding blank line, so no double blank lines are left behind.
     *
     * @return array{0:int,1:int}
     */
    private function deletionRange(string $source, int $start, int $end): array
    {
        if (($source[$end] ?? '') === "\n") {
            $end++;
        }

        if ($start >= 2 && substr($source, $start - 2, 2) === "\n\n") {
            $start--;
        }

        return [$start, $end];
    }

    /**
     * Merges overlapping deletions. When a merged run sits right after the class's opening brace, the blank line
     * that followed it is removed too, so the class never starts with an empty line.
     *
     * @param  array<int, array{0:int,1:int}>  $ranges
     * @return array<int, array{0:int,1:int}>
     */
    private function mergeRanges(array $ranges, ?string $source = null): array
    {
        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $merged = [];
        foreach ($ranges as $range) {
            $last = count($merged) - 1;
            if ($last >= 0 && $range[0] <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $range[1]);
            } else {
                $merged[] = $range;
            }
        }

        if ($source !== null) {
            foreach ($merged as &$range) {
                if ($range[0] >= 2 && substr($source, $range[0] - 2, 2) === "{\n" && ($source[$range[1]] ?? '') === "\n") {
                    $range[1]++;
                }
            }
            unset($range);
        }

        return $merged;
    }

    private function hash(string $memberSource): string
    {
        return sha1((string) preg_replace('/\s+/', ' ', trim($memberSource)));
    }
}
