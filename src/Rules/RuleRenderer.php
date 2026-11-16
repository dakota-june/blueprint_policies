<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintPolicies\Rules;

use InvalidArgumentException;

/**
 * Renders a parsed Rule as the PHP boolean expression that goes after `return`.
 *
 *  - Constant terms are folded: `public | owner` is just `true`, `never & owner` drops out.
 *  - When guests are allowed ($user may be null) every user-dependent term is guarded, so a guest is
 *    denied by the terms that need a user instead of crashing with "method on null".
 *  - `auth` means "signed in". A method that takes `User $user` cannot be called for a guest, so there `auth`
 *    folds away; a method that takes `?User $user` can, so there `auth` is written out as `$user !== null`.
 *  - A negated user check (`!role:banned`) is true for a guest, who by definition does not have the role.
 */
final class RuleRenderer
{
    /** Terms that are false for a guest, so a group holding one already needs a signed-in user. */
    private const USER_TERMS = ['owner', 'role', 'permission', 'admin', 'verified'];

    /** @param  array<string, string>  $checks  term => PHP template; `{{ value }}` is the quoted argument */
    public function __construct(private readonly array $checks) {}

    /**
     * @param  string|null  $modelVariable  e.g. "$post" (or "$post->model()"); null for abilities that receive no model
     * @param  string|null  $ownerColumn  column holding the owner's key; required to render `owner`
     * @param  int  $indent  column the expression starts at, used when the line has to wrap
     *
     * @throws InvalidArgumentException when a term cannot be rendered in this context
     */
    public function render(Rule $rule, bool $nullableUser, ?string $modelVariable, ?string $ownerColumn, int $indent = 15): string
    {
        $alternatives = [];

        foreach ($rule->groups as $group) {
            $parts = [];
            $impliesUser = $this->impliesUser($group);

            foreach ($group as $term) {
                if ($term->name === 'never') {
                    continue 2;                              // this whole AND-group is false
                }

                if ($term->name === 'public') {
                    continue;                                // always true: adds nothing to an AND-group
                }

                if ($term->name === 'auth') {
                    if ($nullableUser && ! $impliesUser) {
                        $parts[] = '$user !== null';         // a guest can reach this method, so say "signed in" out loud
                    }

                    continue;
                }

                $parts[] = $this->term($term, $nullableUser, $modelVariable, $ownerColumn, $rule->source);
            }

            if ($parts === []) {
                return 'true';                               // a group of only true constants: everything passes
            }

            $alternatives[] = $parts;
        }

        if ($alternatives === []) {
            return 'false';
        }

        $wrap = count($alternatives) > 1;
        $expressions = array_map(
            static fn (array $parts): string => $wrap && count($parts) > 1 ? '(' . implode(' && ', $parts) . ')' : implode(' && ', $parts),
            $alternatives,
        );

        $line = implode(' || ', $expressions);

        return strlen($line) + $indent > 100
            ? implode(" ||\n" . str_repeat(' ', 12), $expressions)
            : $line;
    }

    /** @param  array<int, Term>  $group */
    private function impliesUser(array $group): bool
    {
        foreach ($group as $term) {
            if (! $term->negated && in_array($term->name, self::USER_TERMS, true)) {
                return true;
            }
        }

        return false;
    }

    private function term(Term $term, bool $nullable, ?string $model, ?string $ownerColumn, string $source): string
    {
        return match ($term->name) {
            'owner' => $this->owner($term, $nullable, $model, $ownerColumn, $source),
            'state' => $this->state($term, $model, $source),
            'role', 'permission' => $this->userCheck($term, $nullable, $term->name, $term->values),
            default => $this->userCheck($term, $nullable, $term->name, []),
        };
    }

    private function owner(Term $term, bool $nullable, ?string $model, ?string $column, string $source): string
    {
        if ($model === null) {
            throw new InvalidArgumentException("Rule [{$source}]: `owner` needs a model, but this ability receives none.");
        }

        if ($column === null) {
            throw new InvalidArgumentException("Rule [{$source}]: `owner` needs an owner column and the model has none.");
        }

        $compare = '$user->getKey() ' . ($term->negated ? '!==' : '===') . " {$model}->{$column}";

        if (! $nullable) {
            return $compare;
        }

        return $term->negated ? '($user === null || ' . $compare . ')' : '$user !== null && ' . $compare;
    }

    private function state(Term $term, ?string $model, string $source): string
    {
        if ($model === null) {
            throw new InvalidArgumentException("Rule [{$source}]: `state` needs a model, but this ability receives none.");
        }

        $subject = "{$model}->{$term->column}";
        // `!=` and a leading `!` cancel out. (Not `xor`: it binds looser than `=` and would drop the negation.)
        $negate = $term->negated !== $term->negatedComparison;
        $values = array_map($this->literal(...), $term->values);

        if (count($values) === 1) {
            return $subject . ($negate ? ' !== ' : ' === ') . $values[0];
        }

        return ($negate ? '! ' : '') . 'in_array(' . $subject . ', [' . implode(', ', $values) . '], true)';
    }

    /** @param  array<int, string>  $values */
    private function userCheck(Term $term, bool $nullable, string $check, array $values): string
    {
        $template = $this->checks[$check] ?? null;

        if ($template === null) {
            throw new InvalidArgumentException("No PHP is configured for the `{$check}` rule term. Add it under `checks` in config/blueprint_policies.php.");
        }

        $calls = $values === []
            ? [$template]
            : array_map(static fn (string $v): string => str_replace('{{ value }}', "'" . addcslashes($v, "'\\") . "'", $template), $values);

        $call = implode(' || ', $calls);
        $multiple = count($calls) > 1;

        if (! $nullable) {
            return $term->negated || $multiple ? ($term->negated ? '! ' : '') . ($multiple ? "({$call})" : $call) : $call;
        }

        $guarded = '$user !== null && ' . ($multiple ? "({$call})" : $call);

        return $term->negated ? "! ({$guarded})" : $guarded;
    }

    /** Numbers and booleans are written as such, everything else as a quoted string. */
    private function literal(string $value): string
    {
        return match (true) {
            $value === 'true' => 'true',
            $value === 'false' => 'false',
            $value === 'null' => 'null',
            (bool) preg_match('/^-?(0|[1-9]\d*)$/', $value) => $value,
            default => "'" . addcslashes($value, "'\\") . "'",
        };
    }
}
