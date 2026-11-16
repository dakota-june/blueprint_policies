<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintPolicies\Support;

use Dakotajune\BlueprintPolicies\Support\Concerns\ReadsTokens;
use Dakotajune\BlueprintPolicies\Support\Concerns\RemovesImports;
use PhpToken;

/**
 * Registers a Nova policy on its resource: `public static $policy = PostPolicy::class;` (see Nova's resource
 * authorization docs). The property is written with the same `@generated blueprint_policies` tag as everything else,
 * so the rules are the same too:
 *
 *  - our tagged property is rewritten in place when the policy changes;
 *  - a `$policy` you wrote yourself is never touched. It is reported as UNCHANGED when it already names this
 *    policy, and as CONFLICT when it names another one;
 *  - an import is only ever added, and taken back on erase when nothing else uses it.
 *
 * `uses` on the result is empty unless the property is ours, which is how the caller knows whether to record it.
 */
final class ResourcePolicyInjector
{
    use ReadsTokens;
    use RemovesImports;

    public const PROPERTY = 'policy';

    private readonly PolicyClassEditor $editor;

    public function __construct(?PolicyClassEditor $editor = null)
    {
        $this->editor = $editor ?? new PolicyClassEditor();
    }

    public function inject(string $source, string $resourceClass, string $policyFqcn): InjectionResult
    {
        $policyFqcn = ltrim($policyFqcn, '\\');
        [$lf] = $this->toLf($source);
        $tokens = PhpToken::tokenize($lf);
        $classIdx = $this->findClass($tokens, $resourceClass);

        if ($classIdx === null) {
            return new InjectionResult($source, InjectionResult::CLASS_NOT_FOUND);
        }

        $result = $this->editor->update($source, $resourceClass, [self::PROPERTY => $this->member($policyFqcn)], []);

        if ($result->notices !== []) {
            // A `$policy` without our tag is already there: it is yours. Say whether it is the policy we would have set.
            return new InjectionResult(
                $source,
                $this->namesPolicy($lf, $tokens, $classIdx, $policyFqcn) ? InjectionResult::UNCHANGED : InjectionResult::CONFLICT,
            );
        }

        return new InjectionResult($result->source, $result->changed ? InjectionResult::INSERTED : InjectionResult::UNCHANGED, [$policyFqcn]);
    }

    /**
     * Reverse of inject(): removes our tagged property, then the imports it needed if nothing else uses them.
     *
     * @param  array<int, string>  $uses
     */
    public function remove(string $source, string $resourceClass, array $uses = []): string
    {
        $stripped = $this->editor->stripGenerated($source, $resourceClass);

        if ($stripped === $source) {
            return $source;
        }

        [$lf, $eol] = $this->toLf($stripped);
        $cleaned = $this->removeImportsIfUnused($lf, $uses);

        return $cleaned === $lf ? $stripped : $this->fromLf($cleaned, $eol);
    }

    private function member(string $policyFqcn): string
    {
        return implode("\n", [
            '    /**',
            '     * The policy the resource corresponds to.',
            '     *',
            '     * @var class-string',
            '     * ' . PolicyClassEditor::MARKER . ' Rewritten on every build. Remove this tag to take ownership of the property.',
            '     */',
            "    public static \$policy = {{ use:{$policyFqcn} }}::class;",
        ]);
    }

    /** Does the developer's own `$policy = Something::class;` resolve to this policy? */
    private function namesPolicy(string $source, array $tokens, int $classIdx, string $policyFqcn): bool
    {
        if (! preg_match('/\$policy\s*=\s*\\\\?([\w\\\\]+)::class\s*;/', $source, $m)) {
            return false;
        }

        $name = $m[1];

        if (str_contains($name, '\\')) {
            return ltrim($name, '\\') === $policyFqcn;
        }

        $namespace = $this->namespaceOf($source);
        $resolved = $this->imports($tokens, $classIdx)[$name] ?? ltrim(($namespace !== null ? $namespace . '\\' : '') . $name, '\\');

        return $resolved === $policyFqcn;
    }
}
