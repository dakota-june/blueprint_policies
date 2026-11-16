<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintPolicies\Support;

/**
 * Renders the tagged policy methods. Class names are written as `{{ use:Fqcn }}` placeholders, which
 * PolicyClassEditor turns into imports (or leading-backslash names when a short name is taken).
 */
final class PolicyMemberBuilder
{
    private const TAG = '@generated blueprint_policies Rewritten on every build. Remove this tag to take ownership of the method.';

    private const DESCRIPTIONS = [
        'viewAny' => 'Determine whether the user can view any %s.',
        'view' => 'Determine whether the user can view the %s.',
        'create' => 'Determine whether the user can create %s.',
        'update' => 'Determine whether the user can update the %s.',
        'delete' => 'Determine whether the user can delete the %s.',
        'restore' => 'Determine whether the user can restore the %s.',
        'forceDelete' => 'Determine whether the user can permanently delete the %s.',
    ];

    /**
     * @param  string|null  $modelFqcn  the class the method receives after the user (the model, or the Nova resource); null for abilities that act on the class (viewAny, create)
     * @param  string  $modelLabel  lower-case words, e.g. "blog post"
     * @param  string  $modelPlural  used by viewAny / create, e.g. "blog posts"
     */
    public function ability(
        string $name,
        string $userFqcn,
        ?string $modelFqcn,
        string $modelVariable,
        bool $nullableUser,
        string $expression,
        string $ruleSource,
        string $modelLabel,
        string $modelPlural,
    ): string {
        $description = isset(self::DESCRIPTIONS[$name])
            ? sprintf(self::DESCRIPTIONS[$name], in_array($name, ['viewAny', 'create'], true) ? $modelPlural : $modelLabel)
            : 'Determine whether the user can ' . $this->words($name) . ($modelFqcn === null ? " {$modelPlural}." : " the {$modelLabel}.");

        $parameters = ($nullableUser ? '?' : '') . "{{ use:{$userFqcn} }} \$user"
            . ($modelFqcn === null ? '' : ", {{ use:{$modelFqcn} }} \${$modelVariable}");

        return $this->member($description, $ruleSource, "public function {$name}({$parameters}): bool", ["return {$expression};"]);
    }

    public function before(string $userFqcn, string $expression, string $ruleSource): string
    {
        return $this->member(
            'Run before every ability of this policy. Returning null defers to the ability method.',
            $ruleSource,
            "public function before(?{{ use:{$userFqcn} }} \$user, string \$ability): ?bool",
            ["if ({$expression}) {", '    return true;', '}', '', 'return null;'],
        );
    }

    /** @param  array<int, string>  $body */
    private function member(string $description, string $ruleSource, string $signature, array $body): string
    {
        $lines = [
            '    /**',
            "     * {$description}",
            '     *',
            '     * ' . self::TAG,
            '     * Rule: ' . $this->comment($ruleSource),
            '     */',
            "    {$signature}",
            '    {',
        ];

        foreach ($body as $line) {
            $lines[] = $line === '' ? '' : '        ' . $line;
        }

        $lines[] = '    }';

        return implode("\n", $lines);
    }

    /** The rule is the developer's text; keep it on one line and unable to close the docblock it sits in. */
    private function comment(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', str_replace('*/', '* /', $text)));
    }

    /** "publishDraft" => "publish draft" */
    private function words(string $camel): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $camel));
    }
}
