<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintPolicies\Rules;

use InvalidArgumentException;

/**
 * A parsed rule: OR of AND-groups of terms (`&` binds tighter than `|`, there are no parentheses).
 *
 *     owner | role:editor & state:status=draft     =>   [[owner], [role:editor, state:status=draft]]
 *
 * Syntax only is checked here. Whether a column exists, or whether `owner` makes sense for an ability,
 * depends on the model and the ability, so the lexer and generator check those.
 */
final class Rule
{
    public const TERMS = ['public', 'auth', 'never', 'owner', 'role', 'permission', 'admin', 'verified', 'state'];

    /** @param  array<int, array<int, Term>>  $groups */
    private function __construct(
        public readonly string $source,
        public readonly array $groups,
    ) {}

    /** @throws InvalidArgumentException */
    public static function parse(string $text): self
    {
        $source = trim($text);

        if ($source === '') {
            throw new InvalidArgumentException('A rule cannot be empty. Use `never` to deny, `auth` to allow any signed-in user.');
        }

        $groups = [];

        foreach (explode('|', $source) as $alternative) {
            $group = [];

            foreach (explode('&', $alternative) as $piece) {
                $group[] = self::term(trim($piece), $source);
            }

            $groups[] = $group;
        }

        return new self($source, $groups);
    }

    /** True when any term is `public`: guests are allowed, so the policy method must accept a null user. */
    public function allowsGuests(): bool
    {
        return $this->any(static fn (Term $t): bool => $t->name === 'public' && ! $t->negated);
    }

    public function needsModel(): bool
    {
        return $this->any(static fn (Term $t): bool => $t->needsModel());
    }

    /** @return array<int, Term> */
    public function terms(): array
    {
        return array_merge(...$this->groups);
    }

    /** @param  callable(Term): bool  $test */
    private function any(callable $test): bool
    {
        foreach ($this->terms() as $term) {
            if ($test($term)) {
                return true;
            }
        }

        return false;
    }

    private static function term(string $piece, string $source): Term
    {
        if ($piece === '') {
            throw new InvalidArgumentException("Rule [{$source}] has an empty term; check for a stray `|` or `&`.");
        }

        $negated = str_starts_with($piece, '!');
        $piece = ltrim($negated ? substr($piece, 1) : $piece);

        [$name, $argument] = array_pad(explode(':', $piece, 2), 2, null);
        $name = trim((string) $name);

        if (! in_array($name, self::TERMS, true)) {
            throw new InvalidArgumentException(
                "Unknown rule term [{$name}] in [{$source}]. Valid terms: " . implode(', ', self::TERMS) . '.',
            );
        }

        if ($negated && in_array($name, Term::CONSTANT, true)) {
            throw new InvalidArgumentException("Rule [{$source}]: `{$name}` cannot be negated. Say what you mean with `never`, `auth` or `public`.");
        }

        $argument = $argument === null ? null : trim($argument);

        return match ($name) {
            'role', 'permission' => new Term($name, self::values($argument, $name, $source), null, $negated),
            'state' => self::state($argument, $source, $negated),
            default => self::bare($name, $argument, $source, $negated),
        };
    }

    private static function bare(string $name, ?string $argument, string $source, bool $negated): Term
    {
        if ($argument !== null) {
            throw new InvalidArgumentException("Rule [{$source}]: `{$name}` takes no argument.");
        }

        return new Term($name, [], null, $negated);
    }

    /** @return array<int, string> */
    private static function values(?string $argument, string $name, string $source): array
    {
        $values = array_values(array_filter(
            array_map(static fn (string $v): string => trim($v, " \t\"'"), explode(',', (string) $argument)),
            static fn (string $v): bool => $v !== '',
        ));

        if ($values === []) {
            throw new InvalidArgumentException("Rule [{$source}]: `{$name}` needs a name, e.g. `{$name}:" . ($name === 'role' ? 'editor' : 'posts.update') . '`.');
        }

        return $values;
    }

    private static function state(?string $argument, string $source, bool $negated): Term
    {
        if ($argument === null || ! preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*(!=|=)\s*(.+)$/', $argument, $m)) {
            throw new InvalidArgumentException("Rule [{$source}]: `state` needs a column and a value, e.g. `state:status=draft` or `state:status!=archived,deleted`.");
        }

        return new Term('state', self::values($m[3], 'state', $source), $m[1], $negated, $m[2] === '!=');
    }
}
