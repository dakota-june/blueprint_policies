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

it('strips only generated members and spots developer content', function () {
    $editor = new PolicyClassEditor();
    $built = $editor->update(emptyPolicy(), 'PostPolicy', [], ['view' => ability()]);
    $stripped = $editor->stripGenerated($built->source, 'PostPolicy');

    expect($stripped)->not->toContain('function view(')
        ->and($editor->hasDeveloperContent($stripped, 'PostPolicy'))->toBeFalse();

    $mine = str_replace("\n}\n", "\n\n    public function impersonate(\$user): bool\n    {\n        return true;\n    }\n}\n", $stripped);

    expect($editor->hasDeveloperContent($mine, 'PostPolicy'))->toBeTrue();
});
