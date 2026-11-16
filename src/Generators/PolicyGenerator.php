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
use Dakotajune\BlueprintPolicies\Support\ResourcePolicyInjector;
use Dakotajune\BlueprintPolicies\Support\StatementEditor;
use Dakotajune\BlueprintPolicies\Support\TargetConfig;
use Dakotajune\BlueprintPolicies\Support\WiringReverter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Generates policies from the draft's `policies:` and `nova_policies:` blocks, then wires them up.
 * Both blocks run through the same rules, editor and manifest; only the output differs:
 *
 *  `policies:` (target laravel)
 *    1. one policy class per entry (App\Policies), abilities as tagged methods (yours are never touched);
 *    2. tagged authorize statements at the top of the matching resource-controller actions;
 *    3. registration (#[UsePolicy] or Gate::policy) for models Laravel would not find the policy for.
 *
 *  `nova_policies:` (target nova)
 *    1. one policy class per entry (App\Nova\Policies) that receives the Nova resource;
 *    2. `public static $policy = ...` on the Nova resource, when that file exists;
 *    no controller wiring.
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

    /** Nova's relationship methods take more arguments than the one record this generator writes. */
    private const NOVA_RELATIONSHIP_METHOD = '/^(?:add|attach|attachAny|detach)[A-Z]/';

    private readonly NoticeBag $notices;

    private readonly ManifestStore $manifests;

    private readonly PolicyClassEditor $editor;

    private readonly StatementEditor $statements;

    private readonly PolicyAttributeInjector $attributes;

    private readonly ResourcePolicyInjector $resources;

    private readonly WiringReverter $reverter;

    private readonly PolicyMemberBuilder $members;

    /** Null = build one from the target's `checks` config for every rule (what a real build does). */
    private readonly ?RuleRenderer $renderer;

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
        ?ResourcePolicyInjector $resources = null,
        ?WiringReverter $reverter = null,
    ) {
        $this->notices = $notices ?? new NoticeBag();
        $this->manifests = $manifests ?? new ManifestStore($files);
        $this->editor = $editor ?? new PolicyClassEditor();
        $this->statements = $statements ?? new StatementEditor();
        $this->attributes = $attributes ?? new PolicyAttributeInjector();
        $this->renderer = $renderer;
        $this->members = $members ?? new PolicyMemberBuilder();
        $this->resources = $resources ?? new ResourcePolicyInjector($this->editor);
        $this->reverter = $reverter ?? new WiringReverter($files, $this->statements, $this->attributes, $this->resources);
    }

    /** @return array{created: array<int,array<int,string>>, updated: array<int,array<int,string>>} */
    public function output(Tree $tree): array
    {
        $output = ['created' => [], 'updated' => []];

        $registry = $tree->toArray();
        $definitions = [
            ...array_values($registry[PolicyLexer::REGISTRY_KEY] ?? []),
            ...array_values($registry[PolicyLexer::NOVA_REGISTRY_KEY] ?? []),
        ];

        if ($definitions === []) {
            return $output;
        }

        $this->assertNoNativeClash($registry, $definitions);
        $this->assertDistinctFiles($definitions);

        $previous = $this->manifests->load();
        $next = new Manifest();
        $active = [];
        $attribute = [];
        $gate = [];

        foreach ($definitions as $definition) {
            $plan = $this->plan($tree, $definition);

            $path = $this->relativePath($definition->fullyQualifiedClassName());
            $existed = $this->files->exists(base_path($path));
            $created = ! $existed || ($previous->policy($path)['created'] ?? false);
            $active[] = $path;

            $this->writePolicy($definition, $plan, $path, $existed);

            // Blueprint prints each entry as [label, path] and records the path in `.blueprint` (array_column(..., 1)).
            $output[$created ? 'created' : 'updated'][] = ['Policy', $path];
            $next->recordPolicy($path, $definition->className(), $created, $definition->target());

            if ($definition->isNova()) {
                $this->registerWithResource($definition, $path, $next, $output);

                continue;
            }

            $this->wireControllers($tree, $definition, $plan, $path, $next);

            $mode = $this->registrationMode($plan['modelFqcn'], $definition->fullyQualifiedClassName());

            if ($mode === 'attribute') {
                $attribute[] = [$definition, $plan, $path];
            } elseif ($mode === 'gate') {
                $gate[] = [$definition, $plan, $path];
            }
        }

        foreach ($attribute as [$definition, $plan, $path]) {
            $this->registerWithAttribute($definition, $plan, $path, $previous, $next, $output);
        }

        $this->registerWithGate($gate, $next, $output);

        $this->reconcile($previous, $next, $active);

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
     *     modelFqcn: string, model: ?Model, subjectFqcn: string, userFqcn: string, ownerColumn: ?string,
     *     variable: string, controllerVariable: string, label: string, plural: string,
     *     abilities: array<string, array{scope: string, rule: Rule, source: string, nullable: bool, expression: string}>,
     *     before: ?array{source: string, expression: string}
     * }
     */
    private function plan(Tree $tree, PolicyDefinition $definition): array
    {
        $target = $definition->target();
        $modelFqcn = $tree->fqcnForContext($definition->modelName());
        $model = $tree->modelForContext($definition->modelName());
        $label = $definition->name();

        // The controller's parameter is named after the model (`$user`); inside a policy `$user` is the actor, so the
        // policy parameter is `$model` there. Keep the two names apart: only the policy one is renamed.
        $controllerVariable = Str::camel($label);
        $variable = $controllerVariable === 'user' ? 'model' : $controllerVariable;
        $userFqcn = $this->userClass($target);

        // Laravel policies receive the model. Nova policies receive the Nova resource, which reaches its model through model().
        $subjectFqcn = $definition->isNova() ? $definition->resourceFullyQualifiedClassName() : $modelFqcn;
        $subject = '$' . $variable . ($definition->isNova() ? '->model()' : '');

        if ($model === null) {
            $this->notices->add(
                "{$definition->className()}: model [{$definition->modelName()}] is not in the draft, so owner and state rules cannot be checked "
                . 'against its columns. Describe the model under `models:` for full checking.',
            );
        }

        $ownerColumn = $this->ownerColumn($definition, $model, $userFqcn);
        $abilities = $this->abilities($definition, $model, $ownerColumn, $subject);

        return [
            'modelFqcn' => $modelFqcn,
            'model' => $model,
            'subjectFqcn' => $subjectFqcn,
            'userFqcn' => $userFqcn,
            'ownerColumn' => $ownerColumn,
            'variable' => $variable,
            'controllerVariable' => $controllerVariable,
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

        $candidates = [...(array) TargetConfig::get($definition->target(), 'owner_columns', []), Str::snake(Str::afterLast($userFqcn, '\\')) . '_id'];

        foreach ($candidates as $candidate) {
            if ($model->hasColumn($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  string  $subject  how a rule reaches the record: "$post", or "$post->model()" for a Nova resource
     * @return array<string, array{scope: string, rule: Rule, source: string, nullable: bool, expression: string}>
     */
    private function abilities(PolicyDefinition $definition, ?Model $model, ?string $ownerColumn, string $subject): array
    {
        $class = $definition->className();
        $target = $definition->target();
        $listed = $definition->abilities();

        if ($listed === null) {
            $names = (array) TargetConfig::get($target, 'default_abilities', ['viewAny', 'view', 'create', 'update', 'delete']);

            if ($model?->usesSoftDeletes()) {
                $names = [...$names, ...(array) TargetConfig::get($target, 'soft_delete_abilities', ['restore', 'forceDelete'])];
            }

            $listed = [];

            foreach (array_diff(array_unique($names), $definition->except()) as $name) {
                $listed[$name] = [
                    'rule' => null,
                    'scope' => in_array($name, PolicyDefinition::CLASS_ABILITIES, true) ? PolicyDefinition::CLASS_SCOPE : PolicyDefinition::INSTANCE_SCOPE,
                ];
            }
        }

        $defaults = (array) TargetConfig::get($target, 'default_rules', []);
        $fallback = (string) TargetConfig::get($target, 'fallback_rule', 'never');
        $abilities = [];
        $locked = [];

        foreach ($listed as $name => $spec) {
            $explicit = $spec['rule'] !== null;
            $text = $spec['rule'] ?? ($defaults[$name] ?? null);

            if ($definition->isNova() && preg_match(self::NOVA_RELATIONSHIP_METHOD, (string) $name)) {
                $this->notices->add(
                    "{$class}: [{$name}] looks like one of Nova's relationship methods, which receive the related record as well. "
                    . 'It was written with only the user and the resource, so check its signature before relying on it.',
                );
            }

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
                        . '(looked for ' . implode(', ', (array) TargetConfig::get($target, 'owner_columns', [])) . '). Set `owner: column` on the policy.',
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
                'expression' => $this->render($definition, $rule, $nullable, $spec['scope'] === PolicyDefinition::INSTANCE_SCOPE ? $subject : null, $ownerColumn, $name),
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
        $own = $definition->before();

        if ($own === false) {
            return null;                                   // `before: false`: this policy opts out of even a global before
        }

        $text = $own ?? TargetConfig::get($definition->target(), 'before');

        if (! is_string($text) || trim($text) === '') {
            return null;
        }

        $class = $definition->className();
        $rule = $this->parse($text, $class, 'before');

        if ($rule->needsModel()) {
            throw new InvalidArgumentException("Policy [{$class}], before: [{$text}] runs before any record is known, so it cannot use owner or state.");
        }

        if ($rule->allowsGuests()) {
            throw new InvalidArgumentException("Policy [{$class}], before: [{$text}] uses `public`, which would grant every ability to everyone, guests included.");
        }

        return ['source' => $rule->source, 'expression' => $this->render($definition, $rule, true, null, null, 'before')];
    }

    private function parse(string $text, string $class, string $where): Rule
    {
        try {
            return Rule::parse($text);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException("Policy [{$class}], {$where}: " . $e->getMessage(), 0, $e);
        }
    }

    private function render(PolicyDefinition $definition, Rule $rule, bool $nullable, ?string $subject, ?string $ownerColumn, string $where): string
    {
        $renderer = $this->renderer ?? new RuleRenderer(TargetConfig::checks($definition->target()));

        try {
            return $renderer->render($rule, $nullable, $subject, $ownerColumn);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException("Policy [{$definition->className()}], {$where}: " . $e->getMessage(), 0, $e);
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

    private function userClass(string $target): string
    {
        $configured = TargetConfig::get($target, 'user_model') ?: config('auth.providers.users.model');

        if (is_string($configured) && $configured !== '') {
            return ltrim($configured, '\\');
        }

        $models = trim((string) config('blueprint.models_namespace', ''), '\\');

        return trim((string) config('blueprint.namespace', 'App') . ($models !== '' ? '\\' . $models : '') . '\\User', '\\');
    }

    /**
     * @param  array<string, mixed>  $registry
     * @param  array<int, PolicyDefinition>  $definitions
     */
    private function assertNoNativeClash(array $registry, array $definitions): void
    {
        $native = $registry['policies'] ?? [];

        foreach ($native as $policy) {
            $fqcn = $policy->fullyQualifiedClassName();

            foreach ($definitions as $definition) {
                if (! $definition->isNova() && $definition->fullyQualifiedClassName() === $fqcn) {
                    throw new InvalidArgumentException(
                        "Policy [{$policy->className()}] is declared twice: by `meta: policies` on a controller (Blueprint's own policy support) "
                        . 'and by the `policies:` section. Keep one; `policies:` can do everything `meta: policies` does.',
                    );
                }
            }
        }
    }

    /** `policies:` and `nova_policies:` may name the same model, but never write the same file. */
    private function assertDistinctFiles(array $definitions): void
    {
        $seen = [];

        foreach ($definitions as $definition) {
            $path = $this->relativePath($definition->fullyQualifiedClassName());

            if (isset($seen[$path]) && $seen[$path]->target() !== $definition->target()) {
                throw new InvalidArgumentException(
                    "Policy [{$definition->className()}] is declared in both `policies:` and `nova_policies:`, and both would be written to {$path}. "
                    . 'Give the Nova policies their own namespace with `nova.namespace` in config/blueprint_policies.php.',
                );
            }

            $seen[$path] = $definition;
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
                $ability['scope'] === PolicyDefinition::INSTANCE_SCOPE ? $plan['subjectFqcn'] : null,
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
            $this->files->get($this->stubPath($definition)),
        );
    }

    private function stubPath(PolicyDefinition $definition): string
    {
        $custom = base_path('stubs/blueprint/' . ($definition->isNova() ? 'blueprint-nova-policy.stub' : 'blueprint-policy.stub'));

        return $this->files->exists($custom) ? $custom : dirname(__DIR__, 2) . '/stubs/policy.stub';
    }

    // =====================================================================================================
    // Controllers (policies: only)
    // =====================================================================================================

    /** @param  array<string, mixed>  $plan */
    private function wireControllers(Tree $tree, PolicyDefinition $definition, array $plan, string $policyPath, Manifest $manifest): void
    {
        if (! (bool) TargetConfig::get(TargetConfig::LARAVEL, 'controllers.wire', true) || $definition->controller() === false) {
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

        $style = (string) TargetConfig::get(TargetConfig::LARAVEL, 'controllers.style', 'gate');
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
                $statement = $this->call($style, $ability, '$' . $plan['controllerVariable']);
                $required = $plan['controllerVariable'];
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
            $manifest->recordController($path, $class, $uses, $policyPath);
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
        $configured = (string) TargetConfig::get(TargetConfig::LARAVEL, 'registration', 'auto');

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
     * Nova reads `public static $policy` from the resource, so that is where a Nova policy is registered.
     * It never touches the model or the Gate, which is what keeps the Nova policy independent of the web one.
     *
     * @param  array{created: array<int,mixed>, updated: array<int,mixed>}  $output
     */
    private function registerWithResource(PolicyDefinition $definition, string $policyPath, Manifest $next, array &$output): void
    {
        if ((string) TargetConfig::get(TargetConfig::NOVA, 'registration', 'resource') === 'none') {
            return;
        }

        $fqcn = $definition->resourceFullyQualifiedClassName();
        $path = $this->relativePath($fqcn);
        $short = Str::afterLast($fqcn, '\\');

        if (! $this->files->exists(base_path($path))) {
            $this->notices->add(
                "{$definition->className()} was not registered: the Nova resource {$path} does not exist. "
                . "Create it (php artisan nova:resource {$short}) and build again, name it with `resource:`, or set `public static \$policy` yourself.",
            );

            return;
        }

        $source = $this->files->get(base_path($path));
        $result = $this->resources->inject($source, $short, $definition->fullyQualifiedClassName());

        if ($result->status === InjectionResult::CONFLICT) {
            $this->notices->add("{$fqcn} already sets a different `\$policy`, so {$definition->className()} was not registered. That choice is yours.");

            return;
        }

        if ($result->status === InjectionResult::CLASS_NOT_FOUND) {
            $this->notices->add("{$definition->className()} was not registered: class {$fqcn} was not found in {$path}.");

            return;
        }

        if ($result->source !== $source) {
            $this->files->put(base_path($path), $result->source);
            $output['updated'][] = ['Resource', $path];
        }

        if ($result->uses !== []) {                         // empty when the `$policy` is the developer's own line
            $next->recordResource($path, $short, $definition->fullyQualifiedClassName(), $result->uses, $policyPath);
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array{created: array<int,mixed>, updated: array<int,mixed>}  $output
     */
    private function registerWithAttribute(PolicyDefinition $definition, array $plan, string $policyPath, Manifest $previous, Manifest $next, array &$output): void
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
            $output['updated'][] = ['Model', $path];
        }

        // An attribute that was already there is the developer's own unless an earlier build put it there:
        // only then is it ours to remove again on erase.
        if ($result->status === InjectionResult::INSERTED || $previous->model($path) !== null) {
            $next->recordModel($path, Str::afterLast($plan['modelFqcn'], '\\'), $definition->fullyQualifiedClassName(), [
                PolicyAttributeInjector::ATTRIBUTE_FQCN,
                $definition->fullyQualifiedClassName(),
            ], $policyPath);
        }
    }

    /**
     * @param  array<int, array{0: PolicyDefinition, 1: array<string, mixed>, 2: string}>  $entries
     * @param  array{created: array<int,mixed>, updated: array<int,mixed>}  $output
     */
    private function registerWithGate(array $entries, Manifest $next, array &$output): void
    {
        if ($entries === []) {
            return;                                         // lines an earlier build put in a provider are taken back by reconcile()
        }

        $path = (string) TargetConfig::get(TargetConfig::LARAVEL, 'provider', 'app/Providers/AppServiceProvider.php');
        $absolute = base_path($path);

        $lines = [];
        $uses = [self::GATE];
        $owners = [];

        foreach ($entries as [$definition, $plan, $policyPath]) {
            $lines[] = '{{ use:' . self::GATE . ' }}::policy({{ use:' . $plan['modelFqcn'] . ' }}::class, {{ use:' . $definition->fullyQualifiedClassName() . ' }}::class);';
            $uses[] = $plan['modelFqcn'];
            $uses[] = $definition->fullyQualifiedClassName();
            $owners[] = $policyPath;
        }

        $manual = array_map(static fn (array $e): array => [$e[0], $e[1]], $entries);

        if (! $this->files->exists($absolute)) {
            $this->notices->add($this->manualRegistration($path, 'the provider file does not exist', $manual));

            return;
        }

        $class = pathinfo($path, PATHINFO_FILENAME);
        $source = $this->files->get($absolute);
        $result = $this->statements->insert($this->statements->strip($source), $class, 'boot', $lines);

        if ($result->notices !== []) {
            $this->notices->add($this->manualRegistration($path, "{$class}::boot() could not be edited", $manual));

            return;
        }

        if ($result->source !== $source) {
            $this->files->put($absolute, $result->source);
            $output['updated'][] = ['Provider', $path];
        }

        $next->recordProvider($path, $class, $uses, $owners);
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

    // =====================================================================================================
    // Keeping the manifest honest
    // =====================================================================================================

    /**
     * Compares what the last build changed with what this one did.
     *
     *  - An edit a policy of this draft no longer asks for (`controller: false`, another registration mode, a moved
     *    controller or resource ...) is undone, so the file does not keep a line that nothing manages any more.
     *  - A policy that is not in this draft at all (a second draft file, say) keeps its record and its edits,
     *    so `blueprint:erase` can still put them back.
     *
     * @param  array<int, string>  $active  paths of the policy files this build wrote
     */
    private function reconcile(Manifest $previous, Manifest $next, array $active): void
    {
        foreach ($previous->policies() as $path => $meta) {
            if (! in_array($path, $active, true)) {
                $next->adopt(Manifest::POLICIES, $path, $meta);
            }
        }

        $undo = [
            Manifest::CONTROLLERS => $this->reverter->controller(...),
            Manifest::MODELS => $this->reverter->model(...),
            Manifest::RESOURCES => $this->reverter->resource(...),
        ];

        foreach ($undo as $section => $revert) {
            foreach ($previous->section($section) as $path => $meta) {
                if ($next->has($section, $path)) {
                    continue;
                }

                if (in_array($meta['owner'] ?? null, $active, true)) {
                    $revert($path, $meta);

                    continue;
                }

                $next->adopt($section, $path, $meta);
            }
        }

        foreach ($previous->providers() as $path => $meta) {
            if ($next->has(Manifest::PROVIDERS, $path)) {
                continue;
            }

            if (array_intersect((array) ($meta['owners'] ?? []), $active) !== []) {
                $this->reverter->provider($path, $meta);

                continue;
            }

            $next->adopt(Manifest::PROVIDERS, $path, $meta);
        }
    }

    /** Path relative to the project root, built with Blueprint's own helpers. */
    private function relativePath(string $fullyQualifiedClassName): string
    {
        return Blueprint::appPath() . '/'
            . str_replace('\\', '/', Blueprint::relativeNamespace($fullyQualifiedClassName))
            . '.php';
    }
}
