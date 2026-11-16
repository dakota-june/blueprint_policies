<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintPolicies\Models;

use Blueprint\Contracts\Model as BlueprintModel;

/**
 * Tree representation of one entry in the draft's `policies:` block.
 *
 * Deliberately not Blueprint's own `Blueprint\Models\Policy` (the one created by `meta: policies:` on a controller):
 * that lives under the `policies` registry key and has its own generator, and the two must not meet.
 */
final class PolicyDefinition implements BlueprintModel
{
    public const CLASS_SCOPE = 'class';

    public const INSTANCE_SCOPE = 'instance';

    /** Abilities that act on the model class (no record yet); every other ability acts on one record. */
    public const CLASS_ABILITIES = ['viewAny', 'create'];

    /**
     * @param  string  $subNamespace  Namespace below the policies namespace, e.g. "Blog" for the key `Blog\Post`
     * @param  string  $modelName  Model reference as written in the draft (may include a sub-namespace)
     * @param  array<string, array{rule: ?string, scope: string}>|null  $abilities  null = the configured defaults
     * @param  array<int, string>  $except  Abilities removed from the defaults
     * @param  string|false|null  $owner  Owner column; null = detect, false = the model has no owner
     * @param  string|false|null  $controller  Controller to wire; null = detect, false = leave controllers alone
     */
    public function __construct(
        private readonly string $name,
        private readonly string $subNamespace,
        private readonly string $modelName,
        private readonly ?array $abilities,
        private readonly array $except,
        private readonly string|false|null $owner,
        private readonly ?string $before,
        private readonly string|false|null $controller,
    ) {}

    /** The model's short name, e.g. "Post". */
    public function name(): string
    {
        return $this->name;
    }

    /** Namespace relative to the application root namespace, e.g. "Policies" or "Policies\Blog". */
    public function namespace(): string
    {
        $base = trim((string) (config('blueprint.policy_namespace') ?: config('blueprint_policies.namespace', 'Policies')), '\\');

        return trim($base . '\\' . $this->subNamespace, '\\');
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

    public function before(): ?string
    {
        return $this->before;
    }

    public function controller(): string|false|null
    {
        return $this->controller;
    }
}
