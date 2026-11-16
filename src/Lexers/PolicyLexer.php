<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintPolicies\Lexers;

use Blueprint\Contracts\Lexer;
use Dakotajune\BlueprintPolicies\Models\PolicyDefinition;
use Dakotajune\BlueprintPolicies\Rules\Rule;
use Dakotajune\BlueprintPolicies\Support\TargetConfig;
use InvalidArgumentException;

/**
 * Reads and validates the `policies:` and `nova_policies:` sections of the draft. Registry keys:
 * `blueprint_policies` and `blueprint_nova_policies` (never `policies`, which Blueprint's own
 * `meta: policies:` support owns).
 *
 *     policies:                       # App\Policies, wired into controllers
 *       Post:
 *         owner: author_id
 *         before: admin
 *         abilities:
 *           viewAny: public
 *           update: owner | role:editor
 *           publish: role:editor & state:status=draft
 *       Comment:                 # defaults
 *       Tag: false               # opt out (with auto_policies)
 *
 *     nova_policies:                  # App\Nova\Policies, registered on the Nova resource
 *       Post:
 *         resource: Post         # when the Nova resource is not named like the policy
 *         abilities: ...
 *
 * Both sections take the same options, except `controller` (policies only) and `resource` (nova_policies only).
 * Shorthand: `policies: [Post, Comment]` or `policies: Post, Comment`.
 */
final class PolicyLexer implements Lexer
{
    public const REGISTRY_KEY = 'blueprint_policies';

    public const NOVA_REGISTRY_KEY = 'blueprint_nova_policies';

    public const SECTION = 'policies';

    public const NOVA_SECTION = 'nova_policies';

    private const OPTIONS = ['model', 'owner', 'before', 'abilities', 'except'];

    private const LARAVEL_OPTIONS = ['controller'];

    private const NOVA_OPTIONS = ['resource'];

    private const RESERVED_ABILITIES = ['before', 'after'];

    /** The target of the section being read; set by section(). */
    private string $target = TargetConfig::LARAVEL;

    /** @return array{blueprint_policies: array<string, PolicyDefinition>, blueprint_nova_policies: array<string, PolicyDefinition>} */
    public function analyze(array $tokens): array
    {
        return [
            self::REGISTRY_KEY => $this->section($tokens, self::SECTION, TargetConfig::LARAVEL),
            self::NOVA_REGISTRY_KEY => $this->section($tokens, self::NOVA_SECTION, TargetConfig::NOVA),
        ];
    }

    /** @return array<string, PolicyDefinition> */
    private function section(array $tokens, string $section, string $target): array
    {
        $this->target = $target;

        $entries = $this->entries($tokens, $section);
        $policies = [];

        foreach ($entries as $key => $definition) {
            if ($definition === false) {
                continue;                               // `Tag: false` opts a model out, e.g. under auto_policies
            }

            $policy = $this->policy((string) $key, $definition);
            $fqcn = $policy->fullyQualifiedClassName();

            if (isset($policies[$fqcn])) {
                throw new InvalidArgumentException("{$this->subject($policy->className())} is defined more than once in the draft.");
            }

            $policies[$fqcn] = $policy;
        }

        return $policies;
    }

    /** @return array<string, mixed> */
    private function entries(array $tokens, string $sectionName): array
    {
        $section = $tokens[$sectionName] ?? null;
        $entries = [];

        if (is_string($section)) {
            $section = $this->names($section, $sectionName);
        }

        if (is_array($section)) {
            foreach ($section as $key => $value) {
                if (is_int($key)) {
                    $entries[$this->name($value, $sectionName)] = null;     // a list of model names

                    continue;
                }

                $entries[$key] = $value;
            }
        } elseif ($section !== null) {
            throw new InvalidArgumentException("`{$sectionName}:` must be a map of models, or a list of model names.");
        }

        if ((bool) TargetConfig::get($this->target, 'auto_policies', false)) {
            $declared = array_map($this->normalise(...), array_keys($entries));

            foreach ($tokens['models'] ?? [] as $model => $definition) {
                $isPivot = is_array($definition) && ! empty($definition['meta']['pivot']);

                if (! $isPivot && ! in_array($this->normalise((string) $model), $declared, true)) {
                    $entries[(string) $model] = null;
                }
            }
        }

        return $entries;
    }

    private function policy(string $key, mixed $definition): PolicyDefinition
    {
        $definition ??= [];

        if ($definition === true) {
            $definition = [];
        }

        if (! is_array($definition)) {
            throw new InvalidArgumentException("{$this->subject($key)} must be a map of options, empty, or `false`.");
        }

        $options = [...self::OPTIONS, ...($this->target === TargetConfig::NOVA ? self::NOVA_OPTIONS : self::LARAVEL_OPTIONS)];

        foreach (array_keys($definition) as $option) {
            if (! in_array($option, $options, true)) {
                throw new InvalidArgumentException(
                    "{$this->subject($key)} has an unknown option [{$option}]. Valid options: " . implode(', ', $options) . '.',
                );
            }
        }

        $normalised = $this->normalise($key);
        $name = $this->shortName($normalised);
        $sub = $this->subNamespace($normalised);

        if ($name === '' || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException("[{$key}] is not a valid model name for a policy.");
        }

        $model = isset($definition['model']) ? $this->normalise($this->string($definition['model'], $key, 'model')) : $normalised;

        $abilities = array_key_exists('abilities', $definition) ? $this->abilities($definition['abilities'], $key) : null;
        $except = array_key_exists('except', $definition) ? $this->abilityNames($definition['except'], $key, 'except') : [];

        if ($abilities !== null && $except !== []) {
            throw new InvalidArgumentException("{$this->subject($key)}: `except` removes abilities from the defaults, so it cannot be combined with `abilities`.");
        }

        return new PolicyDefinition(
            name: $name,
            subNamespace: $sub,
            modelName: $model,
            abilities: $abilities,
            except: $except,
            owner: $this->owner($definition, $key),
            before: $this->before($definition, $key),
            controller: $this->target === TargetConfig::NOVA ? null : $this->controller($definition, $key),
            target: $this->target,
            resource: $this->target === TargetConfig::NOVA ? $this->resource($definition, $key) : null,
        );
    }

    /** @return array<string, array{rule: ?string, scope: string}> */
    private function abilities(mixed $value, string $key): array
    {
        if ($value === null || $value === [] || $value === '') {
            throw new InvalidArgumentException("{$this->subject($key)}: `abilities` is empty. Leave it out for the defaults, or list at least one ability.");
        }

        if (is_string($value)) {
            $value = $this->names($value, "{$key}.abilities");
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException("{$this->subject($key)}: `abilities` must be a map (ability: rule) or a list of ability names.");
        }

        $abilities = [];

        foreach ($value as $name => $spec) {
            if (is_int($name)) {                          // a list: [view, update]
                $name = $this->string($spec, $key, 'abilities');
                $spec = null;
            }

            $name = (string) $name;
            $this->assertAbilityName($name, $key);

            [$rule, $scope] = $this->abilitySpec($name, $spec, $key);

            $abilities[$name] = ['rule' => $rule, 'scope' => $scope];
        }

        return $abilities;
    }

    /** @return array{0: ?string, 1: string} */
    private function abilitySpec(string $name, mixed $spec, string $key): array
    {
        $scope = in_array($name, PolicyDefinition::CLASS_ABILITIES, true)
            ? PolicyDefinition::CLASS_SCOPE
            : PolicyDefinition::INSTANCE_SCOPE;
        $rule = null;

        if (is_array($spec)) {
            foreach (array_keys($spec) as $option) {
                if (! in_array($option, ['rule', 'scope'], true)) {
                    throw new InvalidArgumentException("{$this->subject($key)}, ability [{$name}]: unknown option [{$option}]. Valid options: rule, scope.");
                }
            }

            $rule = isset($spec['rule']) ? $this->string($spec['rule'], $key, "abilities.{$name}.rule") : null;

            if (isset($spec['scope'])) {
                $scope = $this->string($spec['scope'], $key, "abilities.{$name}.scope");

                if (! in_array($scope, [PolicyDefinition::CLASS_SCOPE, PolicyDefinition::INSTANCE_SCOPE], true)) {
                    throw new InvalidArgumentException("{$this->subject($key)}, ability [{$name}]: scope must be `class` or `instance`, not [{$scope}].");
                }
            }
        } elseif ($spec !== null) {
            $rule = $this->string($spec, $key, "abilities.{$name}");
        }

        if ($rule !== null) {
            $parsed = $this->rule($rule, $key, $name);

            if ($scope === PolicyDefinition::CLASS_SCOPE && $parsed->needsModel()) {
                throw new InvalidArgumentException(
                    "{$this->subject($key)}, ability [{$name}]: rule [{$rule}] looks at a record (owner / state), but `{$name}` acts on the class, not a record.",
                );
            }
        }

        return [$rule, $scope];
    }

    /** @return array<int, string> */
    private function abilityNames(mixed $value, string $key, string $option): array
    {
        $names = is_string($value) ? $this->names($value, "{$key}.{$option}") : $value;

        if (! is_array($names)) {
            throw new InvalidArgumentException("{$this->subject($key)}: `{$option}` must be a list of ability names.");
        }

        $result = [];

        foreach ($names as $name) {
            $name = $this->string($name, $key, $option);
            $this->assertAbilityName($name, $key);
            $result[] = $name;
        }

        return $result;
    }

    private function assertAbilityName(string $name, string $key): void
    {
        if (! preg_match('/^[a-z][A-Za-z0-9]*$/', $name)) {
            throw new InvalidArgumentException("{$this->subject($key)}: [{$name}] is not a valid ability name. Use camelCase, e.g. `update` or `publishDraft`.");
        }

        if (in_array($name, self::RESERVED_ABILITIES, true)) {
            throw new InvalidArgumentException("{$this->subject($key)}: [{$name}] is a policy hook, not an ability. Use the `before:` option for before().");
        }
    }

    private function owner(array $definition, string $key): string|false|null
    {
        if (! array_key_exists('owner', $definition) || $definition['owner'] === null) {
            return null;
        }

        if ($definition['owner'] === false) {
            return false;
        }

        $column = $this->string($definition['owner'], $key, 'owner');

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
            throw new InvalidArgumentException("{$this->subject($key)}: owner [{$column}] is not a column name.");
        }

        return $column;
    }

    /** Absent = the configured global rule (if any); `false` = this policy has no before() at all. */
    private function before(array $definition, string $key): string|false|null
    {
        if (! array_key_exists('before', $definition) || $definition['before'] === null) {
            return null;
        }

        if ($definition['before'] === false) {
            return false;
        }

        $text = $this->string($definition['before'], $key, 'before');
        $rule = $this->rule($text, $key, 'before');

        if ($rule->needsModel()) {
            throw new InvalidArgumentException("{$this->subject($key)}: `before` runs before any record is known, so it cannot use owner or state.");
        }

        return $text;
    }

    private function controller(array $definition, string $key): string|false|null
    {
        if (! array_key_exists('controller', $definition) || $definition['controller'] === null) {
            return null;
        }

        return $definition['controller'] === false ? false : $this->normalise($this->string($definition['controller'], $key, 'controller'));
    }

    private function resource(array $definition, string $key): ?string
    {
        if (! array_key_exists('resource', $definition) || $definition['resource'] === null) {
            return null;
        }

        $name = $this->normalise($this->string($definition['resource'], $key, 'resource'));

        foreach (explode('\\', $name) as $segment) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment)) {
                throw new InvalidArgumentException("{$this->subject($key)}: resource [{$name}] is not a class name.");
            }
        }

        return $name;
    }

    private function rule(string $text, string $key, string $where): Rule
    {
        try {
            return Rule::parse($text);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException("{$this->subject($key)}, {$where}: " . $e->getMessage(), 0, $e);
        }
    }

    private function string(mixed $value, string $key, string $option): string
    {
        if (is_bool($value) || (! is_string($value) && ! is_int($value)) || trim((string) $value) === '') {
            throw new InvalidArgumentException("{$this->subject($key)}: `{$option}` must be a non-empty string.");
        }

        return trim((string) $value);
    }

    /** @return array<int, string> */
    private function names(string $list, string $where): array
    {
        $names = array_values(array_filter(array_map('trim', explode(',', $list)), static fn (string $n): bool => $n !== ''));

        if ($names === []) {
            throw new InvalidArgumentException("`{$where}` is empty.");
        }

        return $names;
    }

    private function name(mixed $value, string $where): string
    {
        return $this->string($value, $where, 'name');
    }

    private function normalise(string $name): string
    {
        return trim(str_replace('/', '\\', $name), '\\ ');
    }

    private function shortName(string $normalised): string
    {
        $parts = explode('\\', $normalised);

        return end($parts) ?: '';
    }

    private function subNamespace(string $normalised): string
    {
        $parts = explode('\\', $normalised);
        array_pop($parts);

        return implode('\\', $parts);
    }

    /** "Policy [Post]" or "Nova policy [Post]", so a message says which section it is about. */
    private function subject(string $key): string
    {
        return ($this->target === TargetConfig::NOVA ? 'Nova policy' : 'Policy') . " [{$key}]";
    }
}
