<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintPolicies\Generators;

use Blueprint\Blueprint;
use Blueprint\Contracts\Generator;
use Blueprint\Models\Controller;
use Blueprint\Models\Model;
use Blueprint\Tree;
use Dakotajune\BlueprintPolicies\Lexers\PolicyLexer;
use Dakotajune\BlueprintPolicies\Models\PolicyDefinition;
use Dakotajune\BlueprintPolicies\Rules\Rule;
use Dakotajune\BlueprintPolicies\Rules\RuleRenderer;
use Dakotajune\BlueprintPolicies\Support\InjectionResult;
use Dakotajune\BlueprintPolicies\Support\Manifest;
use Dakotajune\BlueprintPolicies\Support\ManifestStore;
use Dakotajune\BlueprintPolicies\Support\NoticeBag;
use Dakotajune\BlueprintPolicies\Support\PolicyAttributeInjector;
use Dakotajune\BlueprintPolicies\Support\PolicyClassEditor;
use Dakotajune\BlueprintPolicies\Support\PolicyMemberBuilder;
use Dakotajune\BlueprintPolicies\Support\StatementEditor;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Generates policies from the draft's `policies:` block, then wires them up:
 *
 *  1. one policy class per entry, abilities as tagged methods (yours are never touched);
 *  2. tagged authorize statements at the top of the matching resource-controller actions;
 *  3. registration (#[UsePolicy] or Gate::policy) for models Laravel would not find the policy for.
 */
final class PolicyGenerator implements Generator
{
    private const GATE = 'Illuminate\\Support\\Facades\\Gate';

    /** Resource action => [ability, scope] */
    private const ACTIONS = [
        'index' => ['viewAny', PolicyDefinition::CLASS_SCOPE],
        'create' => ['create', PolicyDefinition::CLASS_SCOPE],
        'store' => ['create', PolicyDefinition::CLASS_SCOPE],
        'show' => ['view', PolicyDefinition::INSTANCE_SCOPE],
        'edit' => ['update', PolicyDefinition::INSTANCE_SCOPE],
        'update' => ['update', PolicyDefinition::INSTANCE_SCOPE],
        'destroy' => ['delete', PolicyDefinition::INSTANCE_SCOPE],
        'restore' => ['restore', PolicyDefinition::INSTANCE_SCOPE],
        'forceDelete' => ['forceDelete', PolicyDefinition::INSTANCE_SCOPE],
    ];

    private readonly NoticeBag $notices;

    private readonly ManifestStore $manifests;

    private readonly PolicyClassEditor $editor;

    private readonly StatementEditor $statements;

    private readonly PolicyAttributeInjector $attributes;

    private readonly RuleRenderer $renderer;

    private readonly PolicyMemberBuilder $members;

    // The Generator contract fixes the first parameter; the rest are optional collaborators.
    public function __construct(
        private readonly Filesystem $files,
        ?NoticeBag $notices = null,
        ?ManifestStore $manifests = null,
        ?PolicyClassEditor $editor = null,
        ?StatementEditor $statements = null,
        ?PolicyAttributeInjector $attributes = null,
        ?RuleRenderer $renderer = null,
        ?PolicyMemberBuilder $members = null,
    ) {
        $this->notices = $notices ?? new NoticeBag();
        $this->manifests = $manifests ?? new ManifestStore($files);
        $this->editor = $editor ?? new PolicyClassEditor();
        $this->statements = $statements ?? new StatementEditor();
        $this->attributes = $attributes ?? new PolicyAttributeInjector();
        $this->renderer = $renderer ?? new RuleRenderer((array) config('blueprint_policies.checks', []));
        $this->members = $members ?? new PolicyMemberBuilder();
    }

    /** @return array{created: array<int,string>, updated: array<int,string>} */
    public function output(Tree $tree): array
    {
        $output = ['created' => [], 'updated' => []];

        /** @var array<string, PolicyDefinition> $definitions */
        $definitions = $tree->toArray()[PolicyLexer::REGISTRY_KEY] ?? [];

        if ($definitions === []) {
            return $output;
        }

        $this->assertNoNativeClash($tree, $definitions);

        $previous = $this->manifests->load();
        $next = new Manifest();
        $attribute = [];
        $gate = [];

        foreach ($definitions as $definition) {
            $plan = $this->plan($tree, $definition);

            $path = $this->relativePath($definition->fullyQualifiedClassName());
            $existed = $this->files->exists(base_path($path));
            $created = ! $existed || ($previous->policy($path)['created'] ?? false);

            $this->writePolicy($definition, $plan, $path, $existed);

            $output[$created ? 'created' : 'updated'][] = $path;
            $next->recordPolicy($path, $definition->className(), $created);

            $this->wireControllers($tree, $definition, $plan, $next);

            $mode = $this->registrationMode($plan['modelFqcn'], $definition->fullyQualifiedClassName());

            if ($mode === 'attribute') {
                $attribute[] = [$definition, $plan];
            } elseif ($mode === 'gate') {
                $gate[] = [$definition, $plan];
            }
        }

        foreach ($attribute as [$definition, $plan]) {
            $this->registerWithAttribute($definition, $plan, $next, $output);
        }

        $this->registerWithGate($gate, $previous, $next, $output);

        $this->manifests->save($next);

        return $output;
    }

    /** @return array<int, string> */
    public function types(): array
    {
        // `policies` so `--only=policies` / `--skip=policies` cover us along with Blueprint's own policy generator.
        return ['policies', 'authorization'];
    }

    // =====================================================================================================
    // Planning: model, owner, abilities, rules
    // =====================================================================================================

    /**
     * @return array{
     *     modelFqcn: string, model: ?Model, userFqcn: string, ownerColumn: ?string, variable: string,
     *     abilities: array<string, array{scope: string, rule: Rule, source: string, nullable: bool, expression: string}>,
     *     before: ?array{source: string, expression: string}
     * }
     */
    private function plan(Tree $tree, PolicyDefinition $definition): array
    {
        $modelFqcn = $tree->fqcnForContext($definition->modelName());
        $model = $tree->modelForContext($definition->modelName());
        $label = $definition->name();
        $variable = Str::camel($definition->name());
        $variable = $variable === 'user' ? 'model' : $variable;
        $userFqcn = $this->userClass();

        if ($model === null) {
            $this->notices->add(
                "{$definition->className()}: model [{$definition->modelName()}] is not in the draft, so owner and state rules cannot be checked "
                . 'against its columns. Describe the model under `models:` for full checking.',
            );
        }

        $ownerColumn = $this->ownerColumn($definition, $model, $userFqcn);
        $abilities = $this->abilities($definition, $model, $ownerColumn, $variable);

        return [
            'modelFqcn' => $modelFqcn,
            'model' => $model,
            'userFqcn' => $userFqcn,
            'ownerColumn' => $ownerColumn,
            'variable' => $variable,
            'abilities' => $abilities,
            'before' => $this->before($definition),
            'label' => strtolower(Str::headline($label)),
            'plural' => strtolower(Str::plural(Str::headline($label))),
        ];
    }

    private function ownerColumn(PolicyDefinition $definition, ?Model $model, string $userFqcn): ?string
    {
        $owner = $definition->owner();

        if ($owner === false) {
            return null;
        }

        if (is_string($owner)) {
            if ($model !== null && ! $model->hasColumn($owner)) {
                throw new InvalidArgumentException("Policy [{$definition->className()}]: owner [{$owner}] is not a column of {$model->name()}.");
            }

            return $owner;
        }

        if ($model === null) {
            return null;
        }

        $candidates = [...(array) config('blueprint_policies.owner_columns', []), Str::snake(Str::afterLast($userFqcn, '\\')) . '_id'];

        foreach ($candidates as $candidate) {
            if ($model->hasColumn($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return array<string, array{scope: string, rule: Rule, source: string, nullable: bool, expression: string}> */
    private function abilities(PolicyDefinition $definition, ?Model $model, ?string $ownerColumn, string $variable): array
    {
        $class = $definition->className();
        $listed = $definition->abilities();

        if ($listed === null) {
            $names = (array) config('blueprint_policies.default_abilities', ['viewAny', 'view', 'create', 'update', 'delete']);

            if ($model?->usesSoftDeletes()) {
                $names = [...$names, ...(array) config('blueprint_policies.soft_delete_abilities', ['restore', 'forceDelete'])];
            }

            $listed = [];

            foreach (array_diff(array_unique($names), $definition->except()) as $name) {
                $listed[$name] = [
                    'rule' => null,
                    'scope' => in_array($name, PolicyDefinition::CLASS_ABILITIES, true) ? PolicyDefinition::CLASS_SCOPE : PolicyDefinition::INSTANCE_SCOPE,
                ];
            }
        }

        $defaults = (array) config('blueprint_policies.default_rules', []);
        $fallback = (string) config('blueprint_policies.fallback_rule', 'never');
        $abilities = [];
        $locked = [];

        foreach ($listed as $name => $spec) {
            $explicit = $spec['rule'] !== null;
            $text = $spec['rule'] ?? ($defaults[$name] ?? null);

            if ($text === null) {
                $this->notices->add("{$class}: ability [{$name}] has no rule, so it is locked (`{$fallback}`). Give it one under `abilities:`.");
                $text = $fallback;
            }

            $rule = $this->parse($text, $class, $name);

            $this->assertScope($rule, $spec['scope'], $class, $name);
            $this->assertStateColumns($rule, $model, $class, $name);

            if ($ownerColumn === null && $this->usesOwner($rule)) {
                if ($explicit) {
                    throw new InvalidArgumentException(
                        "Policy [{$class}], ability [{$name}]: rule [{$text}] uses `owner`, but {$definition->modelName()} has no owner column "
                        . '(looked for ' . implode(', ', (array) config('blueprint_policies.owner_columns', [])) . '). Set `owner: column` on the policy.',
                    );
                }

                $locked[] = $name;
                $text = $fallback;
                $rule = $this->parse($text, $class, $name);
            }

            $nullable = $rule->allowsGuests();

            $abilities[$name] = [
                'scope' => $spec['scope'],
                'rule' => $rule,
                'source' => $rule->source,
                'nullable' => $nullable,
                'expression' => $this->render($rule, $nullable, $spec['scope'] === PolicyDefinition::INSTANCE_SCOPE ? '$' . $variable : null, $ownerColumn, $class, $name),
            ];
        }

        if ($locked !== []) {
            $this->notices->add(
                "{$class}: no owner column was found on {$definition->modelName()}, so " . implode(', ', $locked) . " got `{$fallback}` and are locked. "
                . 'Set `owner: column` on the policy, or give them rules under `abilities:`.',
            );
        }

        return $abilities;
    }

    /** @return array{source: string, expression: string}|null */
    private function before(PolicyDefinition $definition): ?array
    {
        $text = $definition->before() ?? config('blueprint_policies.before');

        if (! is_string($text) || trim($text) === '') {
            return null;
        }

        $rule = $this->parse($text, $definition->className(), 'before');

        return ['source' => $rule->source, 'expression' => $this->render($rule, true, null, null, $definition->className(), 'before')];
    }

    private function parse(string $text, string $class, string $where): Rule
    {
        try {
            return Rule::parse($text);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException("Policy [{$class}], {$where}: " . $e->getMessage(), 0, $e);
        }
    }

    private function render(Rule $rule, bool $nullable, ?string $modelVariable, ?string $ownerColumn, string $class, string $where): string
    {
        try {
            return $this->renderer->render($rule, $nullable, $modelVariable, $ownerColumn);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException("Policy [{$class}], {$where}: " . $e->getMessage(), 0, $e);
        }
    }

    private function assertScope(Rule $rule, string $scope, string $class, string $ability): void
    {
        if ($scope === PolicyDefinition::CLASS_SCOPE && $rule->needsModel()) {
            throw new InvalidArgumentException(
                "Policy [{$class}], ability [{$ability}]: rule [{$rule->source}] looks at a record (owner / state), but `{$ability}` acts on the class, not a record.",
            );
        }
    }

    private function assertStateColumns(Rule $rule, ?Model $model, string $class, string $ability): void
    {
        if ($model === null) {
            return;
        }

        foreach ($rule->terms() as $term) {
            if ($term->name === 'state' && ! $model->hasColumn((string) $term->column)) {
                throw new InvalidArgumentException(
                    "Policy [{$class}], ability [{$ability}]: state column [{$term->column}] is not a column of {$model->name()}.",
                );
            }
        }
    }

    private function usesOwner(Rule $rule): bool
    {
        foreach ($rule->terms() as $term) {
            if ($term->name === 'owner') {
                return true;
            }
        }

        return false;
    }

    private function userClass(): string
    {
        $configured = config('blueprint_policies.user_model') ?: config('auth.providers.users.model');

        if (is_string($configured) && $configured !== '') {
            return ltrim($configured, '\\');
        }

        $models = trim((string) config('blueprint.models_namespace', ''), '\\');

        return trim((string) config('blueprint.namespace', 'App') . ($models !== '' ? '\\' . $models : '') . '\\User', '\\');
    }

    /** @param  array<string, PolicyDefinition>  $definitions */
    private function assertNoNativeClash(Tree $tree, array $definitions): void
    {
        $native = $tree->toArray()['policies'] ?? [];

        foreach ($native as $policy) {
            $fqcn = $policy->fullyQualifiedClassName();

            if (isset($definitions[$fqcn])) {
                throw new InvalidArgumentException(
                    "Policy [{$policy->className()}] is declared twice: by `meta: policies` on a controller (Blueprint's own policy support) "
                    . 'and by the `policies:` section. Keep one; `policies:` can do everything `meta: policies` does.',
                );
            }
        }
    }

    // =====================================================================================================
    // Policy files
    // =====================================================================================================

    /** @param  array<string, mixed>  $plan */
    private function writePolicy(PolicyDefinition $definition, array $plan, string $path, bool $existed): void
    {
        $absolute = base_path($path);
        $methods = [];

        if ($plan['before'] !== null) {
            $methods['before'] = $this->members->before($plan['userFqcn'], $plan['before']['expression'], $plan['before']['source']);
        }

        foreach ($plan['abilities'] as $name => $ability) {
            $methods[$name] = $this->members->ability(
                $name,
                $plan['userFqcn'],
                $ability['scope'] === PolicyDefinition::INSTANCE_SCOPE ? $plan['modelFqcn'] : null,
                $plan['variable'],
                $ability['nullable'],
                $ability['expression'],
                $ability['source'],
                $plan['label'],
                $plan['plural'],
            );
        }

        $source = $existed ? $this->files->get($absolute) : $this->newFile($definition);
        $result = $this->editor->update($source, $definition->className(), [], $methods);

        foreach ($result->notices as $notice) {
            $this->notices->add($notice);
        }

        if (! $existed) {
            $this->files->ensureDirectoryExists(dirname($absolute));
        }

        if (! $existed || $result->changed) {
            $this->files->put($absolute, $result->source);
        }
    }

    private function newFile(PolicyDefinition $definition): string
    {
        return str_replace(
            ['{{ namespace }}', '{{ class }}'],
            [$definition->fullyQualifiedNamespace(), $definition->className()],
            $this->files->get($this->stubPath()),
        );
    }

    private function stubPath(): string
    {
        $custom = base_path('stubs/blueprint/blueprint-policy.stub');

        return $this->files->exists($custom) ? $custom : dirname(__DIR__, 2) . '/stubs/policy.stub';
    }

    // =====================================================================================================
    // Controllers
    // =====================================================================================================

    /** @param  array<string, mixed>  $plan */
    private function wireControllers(Tree $tree, PolicyDefinition $definition, array $plan, Manifest $manifest): void
    {
        if (! (bool) config('blueprint_policies.controllers.wire', true) || $definition->controller() === false) {
            return;
        }

        $controller = $this->controllerFor($tree, $definition);

        if ($controller === null) {
            return;
        }

        if (method_exists($controller, 'policy') && $controller->policy() !== null) {
            $this->notices->add(
                "{$controller->className()} already has authorization from Blueprint's own `meta: policies`, so {$definition->className()} was not wired into it.",
            );

            return;
        }

        $path = $this->relativePath($controller->fullyQualifiedClassName());

        if (! $this->files->exists(base_path($path))) {
            return;
        }

        $style = (string) config('blueprint_policies.controllers.style', 'gate');
        $class = $controller->className();
        $source = $this->statements->strip($this->files->get(base_path($path)));
        $uses = [];

        foreach (array_keys($controller->methods()) as $action) {
            $target = $this->abilityFor((string) $action, $plan['abilities']);

            if ($target === null) {
                continue;
            }

            [$ability, $scope] = $target;

            if ($scope === PolicyDefinition::CLASS_SCOPE) {
                $statement = $this->call($style, $ability, '{{ use:' . $plan['modelFqcn'] . ' }}::class');
                $uses[] = $plan['modelFqcn'];
                $required = null;
            } else {
                $statement = $this->call($style, $ability, '$' . $plan['variable']);
                $required = $plan['variable'];
            }

            if ($style === 'gate') {
                $uses[] = self::GATE;
            }

            $result = $this->statements->insert($source, $class, (string) $action, [$statement], $required, '/authorize\(/');
            $source = $result->source;

            foreach ($result->notices as $notice) {
                $this->notices->add($notice);
            }
        }

        $original = $this->files->get(base_path($path));

        if ($source !== $original) {
            $this->files->put(base_path($path), $source);
        }

        if ($this->statements->hasTagged($source)) {
            $manifest->recordController($path, $class, $uses);
        }
    }

    private function call(string $style, string $ability, string $subject): string
    {
        return ($style === 'authorize' ? '$this->authorize' : '{{ use:' . self::GATE . ' }}::authorize') . "('{$ability}', {$subject});";
    }

    /**
     * Which ability guards a controller action, and whether it acts on the class or on one record.
     * The standard resource actions map as usual; any other action is guarded by the ability of the same name.
     *
     * @param  array<string, array{scope: string}>  $abilities
     * @return array{0: string, 1: string}|null
     */
    private function abilityFor(string $action, array $abilities): ?array
    {
        [$ability] = self::ACTIONS[$action] ?? [$action];

        if (! isset($abilities[$ability])) {
            return null;                      // not generated, so nothing to ask: never reference a missing ability
        }

        return [$ability, $abilities[$ability]['scope']];
    }

    private function controllerFor(Tree $tree, PolicyDefinition $definition): ?Controller
    {
        $wanted = $definition->controller();

        foreach ($tree->controllers() as $controller) {
            // Blueprint keeps a controller's folder apart from its name: `Admin/PostController` is name
            // "PostController" in namespace "Admin". Compare whole paths so Admin\Post is never mistaken for Post.
            $folder = $this->normalise($controller->namespace());
            $path = fn (string $name): string => trim($folder . '\\' . $this->normalise($name), '\\');

            if (is_string($wanted)) {
                $given = $this->normalise($wanted);

                if (in_array($given, [$path($controller->name()), $path($controller->className()), $path($controller->prefix())], true)) {
                    return $controller;
                }

                continue;
            }

            $expected = trim(($definition->subNamespace() !== '' ? $definition->subNamespace() . '\\' : '') . $definition->name(), '\\');

            if ($path($controller->prefix()) === $expected) {
                return $controller;
            }
        }

        return null;
    }

    private function normalise(string $name): string
    {
        return trim(str_replace('/', '\\', $name), '\\ ');
    }

    // =====================================================================================================
    // Registration
    // =====================================================================================================

    /** @return 'attribute'|'gate'|null */
    private function registrationMode(string $modelFqcn, string $policyFqcn): ?string
    {
        $configured = (string) config('blueprint_policies.registration', 'auto');

        if ($configured === 'none' || $this->discoverable($modelFqcn, $policyFqcn)) {
            return null;
        }

        if ($configured === 'attribute' || $configured === 'gate') {
            return $configured;
        }

        return class_exists(PolicyAttributeInjector::ATTRIBUTE_FQCN) ? 'attribute' : 'gate';
    }

    /**
     * Would Laravel find this policy from the model's name alone? Only the flat, conventional layout is trusted:
     * `{root}\Models\Post` or `{root}\Post`, with the policy in `{root}\Policies` (or `{root}\Models\Policies`).
     * Anything else (nested models, custom namespaces) is registered explicitly, which is harmless if redundant.
     */
    private function discoverable(string $modelFqcn, string $policyFqcn): bool
    {
        $namespace = Str::beforeLast($modelFqcn, '\\');
        $short = Str::afterLast($modelFqcn, '\\') . 'Policy';
        $candidates = [$namespace . '\\Policies\\' . $short];

        if (str_ends_with($namespace, '\\Models')) {
            $candidates[] = Str::beforeLast($namespace, '\\Models') . '\\Policies\\' . $short;
        }

        return in_array(ltrim($policyFqcn, '\\'), $candidates, true);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array{created: array<int,string>, updated: array<int,string>}  $output
     */
    private function registerWithAttribute(PolicyDefinition $definition, array $plan, Manifest $next, array &$output): void
    {
        $path = $this->relativePath($plan['modelFqcn']);

        if (! $this->files->exists(base_path($path))) {
            $this->notices->add("{$definition->className()} was not registered: the model file {$path} does not exist.");

            return;
        }

        $source = $this->files->get(base_path($path));
        $result = $this->attributes->inject($source, Str::afterLast($plan['modelFqcn'], '\\'), $definition->fullyQualifiedClassName());

        if ($result->status === InjectionResult::CONFLICT) {
            $this->notices->add("{$plan['modelFqcn']} already names a different policy with #[UsePolicy], so {$definition->className()} was not registered. That choice is yours.");

            return;
        }

        if ($result->status === InjectionResult::CLASS_NOT_FOUND) {
            $this->notices->add("{$definition->className()} was not registered: class {$plan['modelFqcn']} was not found in {$path}.");

            return;
        }

        if ($result->source !== $source) {
            $this->files->put(base_path($path), $result->source);
            $output['updated'][] = $path;
        }

        $next->recordModel($path, Str::afterLast($plan['modelFqcn'], '\\'), $definition->fullyQualifiedClassName(), [
            PolicyAttributeInjector::ATTRIBUTE_FQCN,
            $definition->fullyQualifiedClassName(),
        ]);
    }

    /**
     * @param  array<int, array{0: PolicyDefinition, 1: array<string, mixed>}>  $entries
     * @param  array{created: array<int,string>, updated: array<int,string>}  $output
     */
    private function registerWithGate(array $entries, Manifest $previous, Manifest $next, array &$output): void
    {
        $path = (string) config('blueprint_policies.provider', 'app/Providers/AppServiceProvider.php');
        $absolute = base_path($path);

        if ($entries === []) {
            // Nothing to register any more: take back lines an earlier build put in the provider.
            foreach ($previous->providers() as $recorded => $meta) {
                if ($this->files->exists(base_path($recorded))) {
                    $source = $this->files->get(base_path($recorded));
                    $stripped = $this->statements->strip($source, $meta['uses']);

                    if ($stripped !== $source) {
                        $this->files->put(base_path($recorded), $stripped);
                    }
                }
            }

            return;
        }

        $lines = [];
        $uses = [self::GATE];

        foreach ($entries as [$definition, $plan]) {
            $lines[] = '{{ use:' . self::GATE . ' }}::policy({{ use:' . $plan['modelFqcn'] . ' }}::class, {{ use:' . $definition->fullyQualifiedClassName() . ' }}::class);';
            $uses[] = $plan['modelFqcn'];
            $uses[] = $definition->fullyQualifiedClassName();
        }

        if (! $this->files->exists($absolute)) {
            $this->notices->add($this->manualRegistration($path, 'the provider file does not exist', $entries));

            return;
        }

        $class = pathinfo($path, PATHINFO_FILENAME);
        $source = $this->files->get($absolute);
        $result = $this->statements->insert($this->statements->strip($source), $class, 'boot', $lines);

        if ($result->notices !== []) {
            $this->notices->add($this->manualRegistration($path, "{$class}::boot() could not be edited", $entries));

            return;
        }

        if ($result->source !== $source) {
            $this->files->put($absolute, $result->source);
            $output['updated'][] = $path;
        }

        $next->recordProvider($path, $class, $uses);
    }

    /** @param  array<int, array{0: PolicyDefinition, 1: array<string, mixed>}>  $entries */
    private function manualRegistration(string $path, string $why, array $entries): string
    {
        $lines = array_map(
            static fn (array $e): string => "Gate::policy(\\{$e[1]['modelFqcn']}::class, \\{$e[0]->fullyQualifiedClassName()}::class);",
            $entries,
        );

        return "Policies were not registered ({$why}). Add to {$path} boot(): " . implode(' ', $lines);
    }

    /** Path relative to the project root, built with Blueprint's own helpers. */
    private function relativePath(string $fullyQualifiedClassName): string
    {
        return Blueprint::appPath() . '/'
            . str_replace('\\', '/', Blueprint::relativeNamespace($fullyQualifiedClassName))
            . '.php';
    }
}
