<?php

declare(strict_types=1);

use Dakotajune\BlueprintPolicies\Rules\Rule;
use Dakotajune\BlueprintPolicies\Rules\RuleRenderer;

function renderer(): RuleRenderer
{
    return new RuleRenderer(config('blueprint_policies.checks'));
}

function render(string $rule, bool $guest = false, ?string $model = '$post', ?string $owner = 'user_id'): string
{
    return preg_replace('/\s+/', ' ', renderer()->render(Rule::parse($rule), $guest, $model, $owner));
}

it('folds constants', function () {
    expect(render('public | owner', true))->toBe('true')
        ->and(render('never | admin'))->toBe("\$user->hasRole('admin')")
        ->and(render('never'))->toBe('false')
        ->and(render('never & owner'))->toBe('false');
});

it('binds & tighter than |', function () {
    expect(render('owner | role:a & verified'))
        ->toBe("\$user->getKey() === \$post->user_id || (\$user->hasRole('a') && \$user->hasVerifiedEmail())");
});

it('keeps parentheses around a term with several values', function () {
    expect(render('role:a,b'))->toBe("(\$user->hasRole('a') || \$user->hasRole('b'))")
        ->and(render('role:a,b & verified'))->toBe("(\$user->hasRole('a') || \$user->hasRole('b')) && \$user->hasVerifiedEmail()");
});

it('negates terms', function () {
    expect(render('!role:a'))->toBe("! \$user->hasRole('a')")
        ->and(render('!owner'))->toBe('$user->getKey() !== $post->user_id');
});

it('guards user checks when guests are allowed', function () {
    expect(render('public & role:a', true))->toBe("\$user !== null && \$user->hasRole('a')")
        ->and(render('!admin', true))->toBe("! (\$user !== null && \$user->hasRole('admin'))")
        ->and(render('!owner', true))->toBe('($user === null || $user->getKey() !== $post->user_id)');
});

it('keeps `auth` a real check when guests can reach the method', function () {
    // A method that takes ?User can be called for a guest, so "signed in" has to be written out.
    expect(render('auth', true))->toBe('$user !== null')
        ->and(render('auth & state:status=draft | public & role:a', true))
        ->toBe("(\$user !== null && \$post->status === 'draft') || \$user !== null && \$user->hasRole('a')")
        ->and(render('public & auth', true))->toBe('$user !== null');
});

it('does not repeat the signed-in check when another term already needs a user', function () {
    expect(render('auth & owner', true))->toBe('$user !== null && $user->getKey() === $post->user_id')
        ->and(render('auth & role:a', true))->toBe("\$user !== null && \$user->hasRole('a')")
        ->and(render('auth & !role:a', true))->toBe("\$user !== null && ! (\$user !== null && \$user->hasRole('a'))");
});

it('folds `auth` away when the method takes a plain User', function () {
    // `User $user` cannot be called for a guest at all, so there is nothing left to check.
    expect(render('auth'))->toBe('true')
        ->and(render('auth | role:a'))->toBe('true')
        ->and(render('auth & owner'))->toBe('$user->getKey() === $post->user_id');
});

it('renders state checks, with != and ! cancelling out', function () {
    expect(render('state:status=draft'))->toBe("\$post->status === 'draft'")
        ->and(render('state:status=a,b'))->toBe("in_array(\$post->status, ['a', 'b'], true)")
        ->and(render('state:status!=a'))->toBe("\$post->status !== 'a'")
        ->and(render('!state:status=a'))->toBe("\$post->status !== 'a'")
        ->and(render('!state:status!=a'))->toBe("\$post->status === 'a'");
});

it('reads through whatever expression it is given for the record, e.g. a Nova resource\'s model()', function () {
    expect(render('owner & state:status=draft', false, '$post->model()'))
        ->toBe('$user->getKey() === $post->model()->user_id && $post->model()->status === \'draft\'');
});

it('writes numbers and booleans as such', function () {
    expect(render('state:level=3'))->toBe('$post->level === 3')
        ->and(render('state:active=true'))->toBe('$post->active === true')
        ->and(render('state:code=007'))->toBe("\$post->code === '007'");
});

it('escapes quotes in values', function () {
    expect(render("role:o'brien"))->toBe("\$user->hasRole('o\\'brien')");
});

it('knows when guests are allowed', function () {
    expect(Rule::parse('auth | public')->allowsGuests())->toBeTrue()
        ->and(Rule::parse('auth')->allowsGuests())->toBeFalse()
        ->and(Rule::parse('!role:x')->allowsGuests())->toBeFalse();
});

it('rejects bad rules', function (string $rule, string $message) {
    expect(fn () => Rule::parse($rule))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'empty' => ['', 'empty'],
    'stray pipe' => ['owner |', 'empty term'],
    'unknown term' => ['wat', 'Unknown rule term'],
    'role without a name' => ['role', 'needs a name'],
    'state without a value' => ['state:status', 'needs a column'],
    'argument on a bare term' => ['admin:x', 'takes no argument'],
    'negated constant' => ['!public', 'cannot be negated'],
]);

it('refuses to render a term that cannot work here', function () {
    expect(fn () => renderer()->render(Rule::parse('owner'), false, null, 'user_id'))->toThrow(InvalidArgumentException::class, 'needs a model')
        ->and(fn () => renderer()->render(Rule::parse('owner'), false, '$post', null))->toThrow(InvalidArgumentException::class, 'owner column')
        ->and(fn () => (new RuleRenderer([]))->render(Rule::parse('admin'), false, null, null))->toThrow(InvalidArgumentException::class, 'config/blueprint_policies.php');
});

it('wraps long expressions at ||', function () {
    $long = renderer()->render(Rule::parse('role:aaaaaaaaaaaaaa | role:bbbbbbbbbbbbbbbbbb | role:cccccccccccccccccc | role:dddddddddddddddd'), false, null, null);

    expect($long)->toContain("||\n            ");
});
