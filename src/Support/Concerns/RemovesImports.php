<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintPolicies\Support\Concerns;

use PhpToken;

/** Removes an import we added once nothing in the file refers to it any more. Needs ReadsTokens. */
trait RemovesImports
{
    protected function removeImportIfUnused(string $source, string $fqcn): string
    {
        $fqcn = ltrim($fqcn, '\\');
        $tokens = PhpToken::tokenize($source);
        $classIdx = null;

        foreach ($tokens as $i => $token) {
            if ($token->is(T_CLASS) && ($prev = $this->prevSignificant($tokens, $i)) !== null
                && ! $tokens[$prev]->is([T_DOUBLE_COLON, T_NEW])) {
                $classIdx = $i;
                break;
            }
        }

        if ($classIdx === null) {
            return $source;
        }

        $short = $this->shortName($fqcn);
        $target = null;
        $ranges = [];

        foreach ($this->importStatements($tokens, $classIdx) as [$from, $to]) {
            $ranges[] = [$from, $to];
            $text = '';

            for ($i = $from + 1; $i < $to; $i++) {
                $text .= $tokens[$i]->text;
            }

            if (preg_match('/^\s*\\\\?' . preg_quote($fqcn, '/') . '\s*$/', $text)) {
                $target = [$from, $to];
            }
        }

        if ($target === null) {
            return $source;
        }

        foreach ($tokens as $i => $token) {
            foreach ($ranges as [$from, $to]) {
                if ($i >= $from && $i <= $to) {
                    continue 2;
                }
            }

            if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && $token->text === $short) {
                return $source;                     // still in use somewhere
            }
        }

        $start = $tokens[$target[0]]->pos;
        $end = $tokens[$target[1]]->pos + 1;

        if (isset($source[$end]) && $source[$end] === "\n") {
            $end++;
        }

        return substr_replace($source, '', $start, $end - $start);
    }

    /** @param  array<int, string>  $uses */
    protected function removeImportsIfUnused(string $source, array $uses): string
    {
        foreach ($uses as $use) {
            $source = $this->removeImportIfUnused($source, $use);
        }

        return $source;
    }
}
