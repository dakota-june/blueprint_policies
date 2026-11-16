<?php

declare(strict_types=1);

// NOTE: Feature tests need Laravel, Blueprint and Testbench (composer install). They were written alongside the
// code but could not be executed in the environment that produced this refactor; run `vendor/bin/pest` first.

it('writes a Nova policy to app/Nova/Policies, typed with the Nova resource and reading through model()', function () {
    $this->writeNovaResources();

    $result = $this->build($this->novaDraft(['Post' => ['abilities' => [
        'viewAny' => 'auth',
        'update' => 'owner | role:editor',
        'delete' => 'owner & state:status=draft',
    ]]]));

    $policy = read('app/Nova/Policies/PostPolicy.php');

    expect(array_column($result['created'], 1))->toContain('app/Nova/Policies/PostPolicy.php')
        ->and($policy)->toContain('namespace App\Nova\Policies;')
        ->toContain('use App\Nova\Post;')
        ->toContain('public function update(User $user, Post $post): bool')
        ->toContain('$post->model()->user_id')
        ->toContain("\$post->model()->status === 'draft'")
        ->and(lints($policy))->toBeTrue()
        ->and($this->notices())->toBe([]);
});

it('sets $policy on the resource, once, and a rebuild changes nothing', function () {
    $this->writeNovaResources();
    $draft = $this->novaDraft(['Post' => ['abilities' => ['view' => 'auth']]]);

    $this->build($draft);
    $resource = read('app/Nova/Post.php');
    $before = snapshot();

    $this->build($draft);

    expect($resource)->toContain('public static $policy = PostPolicy::class;')
        ->toContain('use App\Nova\Policies\PostPolicy;')
        ->toContain('@generated blueprint_policies')
        ->and(lints($resource))->toBeTrue()
        ->and(snapshot())->toBe($before);
});

it('sits beside a Laravel policy for the same model without either touching the other', function () {
    $this->writeNovaResources(['Post']);

    $this->build($this->novaDraft(
        ['Post' => ['abilities' => ['view' => 'owner']]],
        ['Post' => ['abilities' => ['view' => 'owner']]],
    ));

    $web = read('app/Policies/PostPolicy.php');
    $nova = read('app/Nova/Policies/PostPolicy.php');

    expect($web)->toContain('$post->user_id')->not->toContain('model()')
        ->and($nova)->toContain('$post->model()->user_id');
});

it('says so when the resource does not exist, but still writes the policy', function () {
    $this->build($this->novaDraft(['Post' => null]));

    expect(base_path('app/Nova/Policies/PostPolicy.php'))->toBeFile()
        ->and(implode("\n", $this->notices()))->toContain('nova:resource Post');
});

it('never replaces a $policy the developer wrote', function () {
    $this->writeNovaResources(['Post']);
    $own = str_replace("public static \$title = 'id';", "public static \$title = 'id';\n\n    public static \$policy = \\App\\Other\\PostPolicy::class;", read('app/Nova/Post.php'));
    file_put_contents(base_path('app/Nova/Post.php'), $own);

    $this->build($this->novaDraft(['Post' => null]));

    expect(read('app/Nova/Post.php'))->toBe($own)
        ->and(implode("\n", $this->notices()))->toContain('already sets a different');
});

it('leaves the resource alone when registration is none', function () {
    $this->writeNovaResources(['Post']);
    $original = read('app/Nova/Post.php');
    config(['blueprint_policies.nova.registration' => 'none']);

    $this->build($this->novaDraft(['Post' => null]));

    expect(read('app/Nova/Post.php'))->toBe($original);
});

it('honours `resource:` and the nova.namespace override', function () {
    $this->writeNovaResources(['Article']);
    $this->build($this->novaDraft(['Post' => ['resource' => 'Article']]));

    expect(read('app/Nova/Policies/PostPolicy.php'))->toContain('use App\Nova\Article;')->toContain('Article $post')
        ->and(read('app/Nova/Article.php'))->toContain('PostPolicy::class');

    config(['blueprint_policies.nova.namespace' => 'Nova\\Auth']);
    $this->build($this->novaDraft(['Tag' => null]));

    expect(base_path('app/Nova/Auth/TagPolicy.php'))->toBeFile();
});

it('never wires controllers for Nova policies', function () {
    $this->writeNovaResources();
    $this->build(['controllers' => ['Post' => ['resource' => 'web']]] + $this->novaDraft(['Post' => null]), ['controllers']);
    $controller = read('app/Http/Controllers/PostController.php');

    $this->build($this->novaDraft(['Post' => null]), ['policies']);

    expect(read('app/Http/Controllers/PostController.php'))->toBe($controller);
});

it('refuses two sections that would write the same file', function () {
    config(['blueprint_policies.nova.namespace' => 'Policies']);

    expect(fn () => $this->build($this->novaDraft(['Post' => null], ['Post' => null])))
        ->toThrow(InvalidArgumentException::class, 'both `policies:` and `nova_policies:`');
});

it('names the second argument $model when the model is User', function () {
    $this->build($this->novaDraft(['User' => ['abilities' => ['view' => 'auth']]]));

    $policy = read('app/Nova/Policies/UserPolicy.php');

    expect($policy)->toContain('public function view(User $user, \App\Nova\User $model): bool')->and(lints($policy))->toBeTrue();
});

it('warns that relationship methods are not generated', function () {
    $this->writeNovaResources(['Post']);
    $this->build($this->novaDraft(['Post' => ['abilities' => ['attachTag' => 'auth']]]));

    expect(implode("\n", $this->notices()))->toContain('relationship methods');
});

it('keeps the two auto_policies switches apart', function () {
    config(['blueprint_policies.auto_policies' => false, 'blueprint_policies.nova.auto_policies' => true]);

    $draft = $this->draft([]);
    unset($draft['policies']);
    $this->build($draft, ['policies']);

    expect(base_path('app/Nova/Policies/PostPolicy.php'))->toBeFile()
        ->and(base_path('app/Policies/PostPolicy.php'))->not->toBeFile();
});
