<?php

declare(strict_types=1);

use Dakotajune\BlueprintPolicies\Lexers\PolicyLexer;

function lex(array $tokens): array
{
    return (new PolicyLexer())->analyze($tokens);
}

it('reads policies: and nova_policies: into separate registries', function () {
    $registry = lex([
        'policies' => ['Post' => null],
        'nova_policies' => ['Post' => ['resource' => 'Article']],
    ]);

    $web = $registry[PolicyLexer::REGISTRY_KEY]['App\Policies\PostPolicy'];
    $nova = $registry[PolicyLexer::NOVA_REGISTRY_KEY]['App\Nova\Policies\PostPolicy'];

    expect($registry[PolicyLexer::REGISTRY_KEY])->toHaveCount(1)
        ->and($registry[PolicyLexer::NOVA_REGISTRY_KEY])->toHaveCount(1)
        ->and($web->isNova())->toBeFalse()
        ->and($nova->isNova())->toBeTrue()
        ->and($nova->resourceFullyQualifiedClassName())->toBe('App\Nova\Article')
        ->and($web->fullyQualifiedClassName())->toBe('App\Policies\PostPolicy');
});

it('names the resource after the policy key unless told otherwise', function () {
    $nova = lex(['nova_policies' => ['Post' => null, 'Admin/Tag' => ['model' => 'Tag']]])[PolicyLexer::NOVA_REGISTRY_KEY];

    expect($nova['App\Nova\Policies\PostPolicy']->resourceFullyQualifiedClassName())->toBe('App\Nova\Post')
        ->and($nova['App\Nova\Policies\Admin\TagPolicy']->resourceFullyQualifiedClassName())->toBe('App\Nova\Admin\Tag')
        ->and($nova['App\Nova\Policies\Admin\TagPolicy']->modelName())->toBe('Tag');
});

it('writes nothing for a section the draft does not have', function () {
    expect(lex([]))->toBe([PolicyLexer::REGISTRY_KEY => [], PolicyLexer::NOVA_REGISTRY_KEY => []])
        ->and(lex(['policies' => ['Post' => null]])[PolicyLexer::NOVA_REGISTRY_KEY])->toBe([]);
});

it('accepts the shorthand lists in both sections', function (string $section) {
    $registry = lex([$section => 'Post, Comment'])[$section === 'policies' ? PolicyLexer::REGISTRY_KEY : PolicyLexer::NOVA_REGISTRY_KEY];
    $list = lex([$section => ['Post', 'Comment']])[$section === 'policies' ? PolicyLexer::REGISTRY_KEY : PolicyLexer::NOVA_REGISTRY_KEY];

    expect($registry)->toHaveCount(2)->and($list)->toHaveCount(2);
})->with(['policies', 'nova_policies']);

it('opts a model out with false in either section', function () {
    expect(lex(['nova_policies' => ['Post' => null, 'Tag' => false]])[PolicyLexer::NOVA_REGISTRY_KEY])->toHaveCount(1);
});

it('allows `controller` only under policies and `resource` only under nova_policies', function (array $tokens, string $message) {
    expect(fn () => lex($tokens))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'controller under nova' => [['nova_policies' => ['Post' => ['controller' => 'PostController']]], 'Nova policy [Post] has an unknown option [controller]'],
    'resource under policies' => [['policies' => ['Post' => ['resource' => 'Post']]], 'Policy [Post] has an unknown option [resource]'],
    'bad resource name' => [['nova_policies' => ['Post' => ['resource' => 'Bad Name']]], 'is not a class name'],
    'nova not a map' => [['nova_policies' => 5], '`nova_policies:` must be a map'],
]);

it('says which section a mistake is in', function () {
    expect(fn () => lex(['nova_policies' => ['Post' => ['abilities' => ['view' => 'wat']]]]))
        ->toThrow(InvalidArgumentException::class, 'Nova policy [Post], view: Unknown rule term')
        ->and(fn () => lex(['policies' => ['Post' => ['abilities' => ['view' => 'wat']]]]))
        ->toThrow(InvalidArgumentException::class, 'Policy [Post], view: Unknown rule term');
});

it('keeps `before: false` apart from no before at all', function () {
    $policies = lex(['policies' => ['Off' => ['before' => false], 'Unset' => null, 'On' => ['before' => 'admin']]])[PolicyLexer::REGISTRY_KEY];

    expect($policies['App\Policies\OffPolicy']->before())->toBeFalse()
        ->and($policies['App\Policies\UnsetPolicy']->before())->toBeNull()
        ->and($policies['App\Policies\OnPolicy']->before())->toBe('admin');
});

it('turns auto_policies on per section', function () {
    $models = ['Post' => ['title' => 'string'], 'Pivot' => ['meta' => ['pivot' => true]]];

    config(['blueprint_policies.auto_policies' => true]);
    $both = lex(['models' => $models]);
    expect($both[PolicyLexer::REGISTRY_KEY])->toHaveCount(1)->and($both[PolicyLexer::NOVA_REGISTRY_KEY])->toBe([]);

    config(['blueprint_policies.auto_policies' => false, 'blueprint_policies.nova.auto_policies' => true]);
    $nova = lex(['models' => $models]);
    expect($nova[PolicyLexer::REGISTRY_KEY])->toBe([])->and($nova[PolicyLexer::NOVA_REGISTRY_KEY])->toHaveCount(1);
});

it('rejects a policy defined twice within a section', function () {
    expect(fn () => lex(['nova_policies' => ['Post' => null, '\\Post' => null]]))
        ->toThrow(InvalidArgumentException::class, 'is defined more than once');
});
