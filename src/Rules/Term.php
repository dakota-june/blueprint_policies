<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintPolicies\Rules;

/** One check in a rule, e.g. `owner`, `!role:admin` or `state:status=draft,review`. */
final class Term
{
    /** Terms that need a model instance, so they only make sense for abilities that receive one. */
    public const NEEDS_MODEL = ['owner', 'state'];

    /** Terms that are constants: they say nothing about the user or the record. */
    public const CONSTANT = ['public', 'auth', 'never'];

    /**
     * @param  array<int, string>  $values  role / permission names, or the allowed values of a state check
     * @param  string|null  $column  state: the model column that is compared
     * @param  bool  $negatedComparison  state: `!=` instead of `=`
     */
    public function __construct(
        public readonly string $name,
        public readonly array $values = [],
        public readonly ?string $column = null,
        public readonly bool $negated = false,
        public readonly bool $negatedComparison = false,
    ) {}

    public function needsModel(): bool
    {
        return in_array($this->name, self::NEEDS_MODEL, true);
    }

    public function isConstant(): bool
    {
        return in_array($this->name, self::CONSTANT, true);
    }

    /** The text this term was parsed from, normalised. */
    public function __toString(): string
    {
        $text = $this->name;

        if ($this->name === 'state') {
            $text .= ':' . $this->column . ($this->negatedComparison ? '!=' : '=') . implode(',', $this->values);
        } elseif ($this->values !== []) {
            $text .= ':' . implode(',', $this->values);
        }

        return ($this->negated ? '!' : '') . $text;
    }
}
