<?php

declare(strict_types=1);

use Dakotajune\BlueprintPolicies\Support\PolicyClassEditor;
use Dakotajune\BlueprintPolicies\Support\PolicyMemberBuilder;

function emptyPolicy(): string
{
    return "<?php\n\nnamespace App\\Policies;\n\nclass PostPolicy\n{\n}\n";
}

function ability(string $name = 'view', string $expression = 'true'): string
{
    return (new PolicyMemberBuilder())->ability($name, 'App\Models\User', 'App\Models\Post', 'post', false, $expression, 'auth', 'post', 'posts');
}

function taggedProperty(string $name = 'policy', string $value = 'Foo::class'): string
{
    return "    /**\n     * Generated.\n     *\n     * @generated blueprint_policies\n     */\n    public static \${$name} = {$value};";
}

it('writes tagged abilities and imports the classes they use', function () {
    $result = (new PolicyClassEditor())->update(emptyPolicy(), 'PostPolicy', [], ['view' => ability()]);

    expect($result->changed)->toBeTrue()
        ->and($result->source)->toContain('public function view(User $user, Post $post): bool')
        ->toContain('use App\Models\User;')->toContain('use App\Models\Post;')
        ->toContain('@generated blueprint_policies')
        ->and(lints($result->source))->toBeTrue();
});

it('is idempotent, and deletes abilities that are no longer wanted', function () {
    $editor = new PolicyClassEditor();
    $both = $editor->update(emptyPolicy(), 'PostPolicy', [], ['view' => ability(), 'delete' => ability('delete')]);
    $again = $editor->update($both->source, 'PostPolicy', [], ['view' => ability(), 'delete' => ability('delete')]);
    $fewer = $editor->update($both->source, 'PostPolicy', [], ['view' => ability()]);

    expect($again->changed)->toBeFalse()
        ->and($fewer->source)->not->toContain('function delete(')->toContain('function view(')
        ->and(lints($fewer->source))->toBeTrue();
});

it('never touches a method of the same name that lost its tag', function () {
    $editor = new PolicyClassEditor();
    $built = $editor->update(emptyPolicy(), 'PostPolicy', [], ['view' => ability('view', 'true')]);
    $owned = preg_replace('/\s*\* @generated[^\n]*\n/', "\n", $built->source);
    $owned = str_replace('return true;', 'return false;', $owned);

    $result = $editor->update($owned, 'PostPolicy', [], ['view' => ability('view', 'true')]);

    expect($result->source)->toContain('return false;')->and($result->notices)->not->toBeEmpty();
});

it('adds no imports for a member it leaves to the developer', function () {
    $editor = new PolicyClassEditor();
    $mine = "<?php\n\nnamespace App\\Policies;\n\nclass PostPolicy\n{\n    public function view(\$user, \$post): bool\n    {\n        return false;\n    }\n}\n";

    $result = $editor->update($mine, 'PostPolicy', [], ['view' => ability('view')]);

    expect($result->changed)->toBeFalse()->and($result->source)->toBe($mine)->and($result->notices)->toHaveCount(1);
});

it('strips only generated members and spots developer content', function () {
    $editor = new PolicyClassEditor();
    $built = $editor->update(emptyPolicy(), 'PostPolicy', [], ['view' => ability()]);
    $stripped = $editor->stripGenerated($built->source, 'PostPolicy');

    expect($stripped)->not->toContain('function view(')
        ->and($editor->hasDeveloperContent($stripped, 'PostPolicy'))->toBeFalse();

    $mine = str_replace("\n}\n", "\n\n    public function impersonate(\$user): bool\n    {\n        return true;\n    }\n}\n", $stripped);

    expect($editor->hasDeveloperContent($mine, 'PostPolicy'))->toBeTrue();
});

it('writes, rewrites and removes a tagged property, and leaves the developer\'s own properties alone', function () {
    $editor = new PolicyClassEditor();
    $source = "<?php\n\nnamespace App\\Nova;\n\nclass Post\n{\n    public static \$model = 'x';\n\n    public static \$title = 'id';\n}\n";

    $first = $editor->update($source, 'Post', ['policy' => taggedProperty('policy', 'Foo::class')], []);
    $again = $editor->update($first->source, 'Post', ['policy' => taggedProperty('policy', 'Foo::class')], []);
    $moved = $editor->update($first->source, 'Post', ['policy' => taggedProperty('policy', 'Bar::class')], []);
    $gone = $editor->update($first->source, 'Post', [], []);

    expect($first->changed)->toBeTrue()
        ->and($first->source)->toContain('public static $policy = Foo::class;')->toContain("public static \$model = 'x';")
        ->and(lints($first->source))->toBeTrue()
        ->and($again->changed)->toBeFalse()
        ->and($moved->source)->toContain('Bar::class')->not->toContain('Foo::class')
        ->and($gone->source)->toBe($source);
});

it('does not rewrite a property that lost its tag', function () {
    $editor = new PolicyClassEditor();
    $source = "<?php\n\nnamespace App\\Nova;\n\nclass Post\n{\n    public static \$policy = Mine::class;\n}\n";

    $result = $editor->update($source, 'Post', ['policy' => taggedProperty()], []);

    expect($result->changed)->toBeFalse()->and($result->notices)->toHaveCount(1)->and($result->source)->toBe($source);
});
