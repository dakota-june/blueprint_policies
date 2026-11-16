<?php

declare(strict_types=1);

use Dakotajune\BlueprintPolicies\Support\InjectionResult;
use Dakotajune\BlueprintPolicies\Support\ResourcePolicyInjector;

const RESOURCE = <<<'PHP'
<?php

namespace App\Nova;

use Laravel\Nova\Resource;

class Post extends Resource
{
    public static $model = \App\Models\Post::class;

    public static $title = 'id';
}

PHP;

const NOVA_POLICY = 'App\Nova\Policies\PostPolicy';

it('sets a tagged $policy on the resource and imports the policy', function () {
    $result = (new ResourcePolicyInjector())->inject(RESOURCE, 'Post', NOVA_POLICY);

    expect($result->status)->toBe(InjectionResult::INSERTED)
        ->and($result->uses)->toBe([NOVA_POLICY])
        ->and($result->source)->toContain('public static $policy = PostPolicy::class;')
        ->toContain('use App\Nova\Policies\PostPolicy;')
        ->toContain('@generated blueprint_policies')
        ->toContain("public static \$model = \\App\\Models\\Post::class;")
        ->and(lints($result->source))->toBeTrue();
});

it('is idempotent', function () {
    $injector = new ResourcePolicyInjector();
    $first = $injector->inject(RESOURCE, 'Post', NOVA_POLICY);
    $again = $injector->inject($first->source, 'Post', NOVA_POLICY);

    expect($again->status)->toBe(InjectionResult::UNCHANGED)
        ->and($again->source)->toBe($first->source)
        ->and($again->uses)->toBe([NOVA_POLICY]);       // still ours, so the caller keeps recording it
});

it('rewrites its own property when the policy moves', function () {
    $injector = new ResourcePolicyInjector();
    $first = $injector->inject(RESOURCE, 'Post', NOVA_POLICY);
    $moved = $injector->inject($first->source, 'Post', 'App\Nova\Auth\PostPolicy');

    // The old import still owns the short name `PostPolicy` (imports are only ever added), so the new class is written
    // fully qualified rather than silently pointing at the old one.
    expect($moved->status)->toBe(InjectionResult::INSERTED)
        ->and($moved->uses)->toBe(['App\Nova\Auth\PostPolicy'])
        ->and($moved->source)->toContain('public static $policy = \App\Nova\Auth\PostPolicy::class;')
        ->and(substr_count($moved->source, '$policy ='))->toBe(1)
        ->and(lints($moved->source))->toBeTrue();
});

it('writes a short name when nothing else holds it', function () {
    $result = (new ResourcePolicyInjector())->inject(RESOURCE, 'Post', 'App\Nova\Auth\PostPolicy');

    expect($result->source)->toContain('public static $policy = PostPolicy::class;')->toContain('use App\Nova\Auth\PostPolicy;');
});

it('removes exactly what it added', function () {
    $injector = new ResourcePolicyInjector();
    $result = $injector->inject(RESOURCE, 'Post', NOVA_POLICY);

    expect($injector->remove($result->source, 'Post', $result->uses))->toBe(RESOURCE);
});

it('leaves a $policy you wrote alone, saying whether it is this policy', function (string $line, string $import, string $status) {
    $own = str_replace(
        "    public static \$title = 'id';",
        "    public static \$title = 'id';\n\n    {$line}",
        str_replace('use Laravel\Nova\Resource;', "use Laravel\\Nova\\Resource;\n{$import}", RESOURCE),
    );

    $result = (new ResourcePolicyInjector())->inject($own, 'Post', NOVA_POLICY);

    expect($result->status)->toBe($status)
        ->and($result->source)->toBe($own)
        ->and($result->uses)->toBe([]);                  // not ours: the caller must not record it, erase must not remove it
})->with([
    'the same policy by short name' => ['public static $policy = PostPolicy::class;', 'use App\Nova\Policies\PostPolicy;', InjectionResult::UNCHANGED],
    'the same policy fully qualified' => ['public static $policy = \App\Nova\Policies\PostPolicy::class;', '', InjectionResult::UNCHANGED],
    'another policy of the same short name' => ['public static $policy = PostPolicy::class;', 'use App\Other\PostPolicy;', InjectionResult::CONFLICT],
    'another policy' => ['public static $policy = \App\Other\Thing::class;', '', InjectionResult::CONFLICT],
]);

it('does not remove a $policy that is not tagged', function () {
    $own = str_replace("    public static \$title = 'id';", "    public static \$title = 'id';\n\n    public static \$policy = \\App\\Other\\Thing::class;", RESOURCE);

    expect((new ResourcePolicyInjector())->remove($own, 'Post', ['App\Other\Thing']))->toBe($own);
});

it('reports a class that is not in the file', function () {
    expect((new ResourcePolicyInjector())->inject(RESOURCE, 'Comment', NOVA_POLICY)->status)->toBe(InjectionResult::CLASS_NOT_FOUND);
});
