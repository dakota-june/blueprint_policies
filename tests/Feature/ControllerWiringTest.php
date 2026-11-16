<?php

declare(strict_types=1);

it('adds a tagged check to each resource action', function () {
    $this->build($this->draft());
    $controller = read('app/Http/Controllers/PostController.php');

    expect($controller)
        ->toContain("Gate::authorize('viewAny', Post::class); // @generated blueprint_policies")
        ->toContain("Gate::authorize('create', Post::class); // @generated blueprint_policies")
        ->toContain("Gate::authorize('view', \$post); // @generated blueprint_policies")
        ->toContain("Gate::authorize('update', \$post); // @generated blueprint_policies")
        ->toContain("Gate::authorize('delete', \$post); // @generated blueprint_policies")
        ->toContain('use Illuminate\Support\Facades\Gate;')
        ->and(lints($controller))->toBeTrue();
});

it('is byte-identical on rebuild, whether or not Blueprint rewrote the controllers', function () {
    $this->build($this->draft());
    $first = snapshot();

    $this->build($this->draft(), ['policies']);
    expect(snapshot())->toBe($first);

    $this->build($this->draft());
    expect(snapshot())->toBe($first);
});

it('never references an ability that was not generated', function () {
    $this->build($this->draft(['Post' => ['except' => ['forceDelete', 'delete']]]));
    $controller = read('app/Http/Controllers/PostController.php');

    expect($controller)->not->toContain("'delete'")->toContain("Gate::authorize('update', \$post);");
});

it('can use $this->authorize instead', function () {
    config(['blueprint_policies.controllers.style' => 'authorize']);
    $this->build($this->draft(['Post' => null]));
    $controller = read('app/Http/Controllers/PostController.php');

    expect($controller)
        ->toContain("\$this->authorize('view', \$post); // @generated blueprint_policies")
        ->not->toContain('Facades\Gate');
});

it('leaves controllers alone when told to', function (callable $configure, array $policies) {
    $configure();
    $this->build($this->draft($policies));

    expect(read('app/Http/Controllers/PostController.php'))->not->toContain('@generated');
})->with([
    'wire: false' => [fn () => config(['blueprint_policies.controllers.wire' => false]), ['Post' => null]],
    'controller: false' => [fn () => null, ['Post' => ['controller' => false]]],
]);

it('does not guess a controller in another folder', function () {
    $draft = $this->draft(['Post' => null]);
    unset($draft['controllers']['Post']);
    $draft['controllers']['Admin/PostController'] = ['index' => ['query' => 'all', 'render' => 'admin.post.index']];

    $this->build($draft);

    expect(read('app/Http/Controllers/Admin/PostController.php'))->not->toContain('authorize');
});

it('wires a namespaced controller when the policy or the controller option names it', function (array $policies) {
    $draft = $this->draft($policies);
    $draft['controllers']['Admin/PostController'] = ['index' => ['query' => 'all', 'render' => 'admin.post.index']];

    $this->build($draft);

    expect(read('app/Http/Controllers/Admin/PostController.php'))->toContain("Gate::authorize('viewAny', Post::class);");
})->with([
    'by the policy key' => [['Admin/Post' => ['model' => 'Post']]],
    'by the controller option' => [['Post' => ['controller' => 'Admin/PostController']]],
]);

it('does not wire a controller that Blueprint authorizes itself', function () {
    $draft = $this->draft(['Comment' => null]);
    $draft['controllers']['Post']['meta'] = ['policies' => 'index, show'];

    $this->build($draft);

    expect(read('app/Http/Controllers/PostController.php'))->not->toContain('@generated blueprint_policies');
});

it("respects a developer's own authorize() call", function () {
    $this->build($this->draft(['Post' => null]));
    $path = base_path('app/Http/Controllers/PostController.php');
    file_put_contents($path, str_replace(
        "        Gate::authorize('view', \$post); // @generated blueprint_policies\n",
        "        \$this->authorize('view', \$post);\n",
        file_get_contents($path),
    ));

    $this->build($this->draft(['Post' => null]), ['policies']);

    expect(substr_count(file_get_contents($path), "authorize('view'"))->toBe(1);
});
