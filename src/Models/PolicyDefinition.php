<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintPolicies\Models;

use Blueprint\Contracts\Model as BlueprintModel;
use Dakotajune\BlueprintPolicies\Support\TargetConfig;

/**
 * Tree representation of one entry in the draft's `policies:` or `nova_policies:` block.
 *
 * Deliberately not Blueprint's own `Blueprint\Models\Policy` (the one created by `meta: policies:` on a controller):
 * that lives under the `policies` registry key and has its own generator, and the two must not meet.
 */
final class PolicyDefinition implements BlueprintModel
{
    public const CLASS_SCOPE = 'class';

    public const INSTANCE_SCOPE = 'instance';

    /** `extends: ~` under `nova_policies:`: extend the web policy of the same model. */
    public const EXTENDS_WEB = ':web';

    /** Abilities that act on the model class (no record yet); every other ability acts on one record. */
    public const CLASS_ABILITIES = ['viewAny', 'create'];

    /**
     * @param  string  $subNamespace  Namespace below the policies namespace, e.g. "Blog" for the key `Blog\Post`
     * @param  string  $modelName  Model reference as written in the draft (may include a sub-namespace)
     * @param  array<string, array{rule: ?string, scope: string}>|null  $abilities  null = the configured defaults
     * @param  array<int, string>  $except  Abilities removed from the defaults
     * @param  string|false|null  $owner  Owner column; null = detect, false = the model has no owner
     * @param  string|false|null  $before  A rule granting everything; null = the configured global one, false = none at all
     * @param  string|false|null  $extends  Parent class as written in the draft; null = the configured default, false = none, EXTENDS_WEB = the web policy (nova target only)
     * @param  string|false|null  $controller  Controller to wire; null = detect, false = leave controllers alone (laravel target only)
     * @param  string  $target  TargetConfig::LARAVEL (`policies:`) or TargetConfig::NOVA (`nova_policies:`)
     * @param  string|null  $resource  Nova resource the policy is for, e.g. "Post" or "Admin\Post"; null = same path as the policy key (nova target only)
     */
    public function __construct(
        private readonly string $name,
        private readonly string $subNamespace,
        private readonly string $modelName,
        private readonly ?array $abilities,
        private readonly array $except,
        private readonly string|false|null $owner,
        private readonly string|false|null $before,
        private readonly string|false|null $controller,
        private readonly string $target = TargetConfig::LARAVEL,
        private readonly ?string $resource = null,
        private readonly string|false|null $extends = null,
    ) {}

    /** The model's short name, e.g. "Post". */
    public function name(): string
    {
        return $this->name;
    }

    public function target(): string
    {
        return $this->target;
    }

    public function isNova(): bool
    {
        return $this->target === TargetConfig::NOVA;
    }

    /**
     * Namespace relative to the application root namespace, e.g. "Policies", "Policies\Blog" or "Nova\Policies".
     * Blueprint's own `blueprint.policy_namespace` only ever applies to the laravel target.
     */
    public function namespace(): string
    {
        $base = $this->isNova()
            ? (string) TargetConfig::get(TargetConfig::NOVA, 'namespace', 'Nova\\Policies')
            : (string) (config('blueprint.policy_namespace') ?: config('blueprint_policies.namespace', 'Policies'));

        return trim(trim($base, '\\') . '\\' . $this->subNamespace, '\\');
    }

    /** e.g. "App\Policies" */
    public function fullyQualifiedNamespace(): string
    {
        return trim((string) config('blueprint.namespace', 'App') . '\\' . $this->namespace(), '\\');
    }

    public function className(): string
    {
        return $this->name . 'Policy';
    }

    /** e.g. "App\Policies\PostPolicy" */
    public function fullyQualifiedClassName(): string
    {
        return $this->fullyQualifiedNamespace() . '\\' . $this->className();
    }

    /** Namespace below the policies namespace, e.g. "Blog" for the key `Blog\Post`; empty for none. */
    public function subNamespace(): string
    {
        return $this->subNamespace;
    }

    public function modelName(): string
    {
        return $this->modelName;
    }

    /**
     * The class the generated policy extends, fully qualified, or null for none.
     *
     * `extends:` on the policy wins, then `extends` in the config (per target). A name containing a backslash is taken as
     * fully qualified (`\App\Support\BasePolicy`, `App\Support\BasePolicy`); a bare or slash name is relative to the
     * policies namespace (`BasePolicy` is App\Policies\BasePolicy, App\Nova\Policies\BasePolicy for Nova).
     */
    public function extendsClass(): ?string
    {
        if ($this->extendsWebPolicy()) {
            return $this->webPolicyClass();
        }

        $value = $this->extends ?? TargetConfig::get($this->target, 'extends');

        if ($value === null || $value === false || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);

        if (str_contains($value, '\\')) {
            return ltrim($value, '\\');
        }

        $base = trim((string) config('blueprint.namespace', 'App') . '\\' . trim(
            $this->isNova()
                ? (string) TargetConfig::get(TargetConfig::NOVA, 'namespace', 'Nova\\Policies')
                : (string) (config('blueprint.policy_namespace') ?: config('blueprint_policies.namespace', 'Policies')),
            '\\',
        ), '\\');

        return $base . '\\' . str_replace('/', '\\', $value);
    }

    /** True for a Nova policy written with `extends: ~`: it extends the `policies:` policy of the same model. */
    public function extendsWebPolicy(): bool
    {
        return $this->isNova() && $this->extends === self::EXTENDS_WEB;
    }

    /** The web policy for the same key, e.g. "App\Policies\ContactTypePolicy" (top-level config, whatever the Nova block says). */
    public function webPolicyClass(): string
    {
        $base = trim((string) (config('blueprint.policy_namespace') ?: config('blueprint_policies.namespace', 'Policies')), '\\');

        return implode('\\', array_filter([(string) config('blueprint.namespace', 'App'), $base, $this->subNamespace, $this->className()], static fn (string $part): bool => $part !== ''));
    }

    /** The Nova resource this policy is for, relative to the resources namespace, e.g. "Post". */
    public function resourceName(): string
    {
        return $this->resource ?? trim($this->subNamespace . '\\' . $this->name, '\\');
    }

    /** e.g. "App\Nova\Post" */
    public function resourceFullyQualifiedClassName(): string
    {
        $namespace = trim((string) TargetConfig::get(TargetConfig::NOVA, 'resource_namespace', 'Nova'), '\\');

        return trim((string) config('blueprint.namespace', 'App') . '\\' . $namespace . '\\' . $this->resourceName(), '\\');
    }

    /** @return array<string, array{rule: ?string, scope: string}>|null */
    public function abilities(): ?array
    {
        return $this->abilities;
    }

    /** @return array<int, string> */
    public function except(): array
    {
        return $this->except;
    }

    public function owner(): string|false|null
    {
        return $this->owner;
    }

    public function before(): string|false|null
    {
        return $this->before;
    }

    public function controller(): string|false|null
    {
        return $this->controller;
    }
}
