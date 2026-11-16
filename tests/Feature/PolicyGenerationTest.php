<?php

declare(strict_types=1);

it('generates a lint-clean policy per entry, with every ability tagged', function () {
    $out = $this->build($this->draft());

    expect($out['created'])->toContain('app/Policies/PostPolicy.php', 'app/Policies/CommentPolicy.php', 'app/Policies/TagPolicy.php');

    foreach (['Post', 'Comment', 'Tag'] as $name) {
        expect(lints(read("app/Policies/{$name}Policy.php")))->toBeTrue();
    }

    expect(read('app/Policies/PostPolicy.php'))
        ->toContain('namespace App\Policies;')
        ->toContain('use App\Models\Post;')
        ->toContain('use App\Models\User;')
        ->toContain('@generated blueprint_policies');
});

it('turns rules into the right PHP', function () {
    $this->build($this->draft());
    $policy = preg_replace('/\s+/', ' ', read('app/Policies/PostPolicy.php'));

    expect($policy)
        ->toContain("public function before(?User \$user, string \$ability): ?bool { if (\$user !== null && \$user->hasRole('admin')) { return true; } return null; }")
        ->toContain('public function viewAny(?User $user): bool { return true; }')
        ->toContain('public function view(?User $user, Post $post): bool { return true; }')
        ->toContain('public function create(User $user): bool { return $user->hasVerifiedEmail(); }')
        ->toContain("return \$user->getKey() === \$post->user_id || \$user->hasRole('editor');")
        ->toContain("(\$user->getKey() === \$post->user_id && \$post->status === 'draft') || \$user->hasRole('admin');")
        ->toContain("return \$user->hasRole('editor') && \$post->status === 'draft';");
});

it('uses the configured defaults, detects the owner and adds soft-delete abilities', function () {
    $this->build($this->draft(['Post' => null, 'Comment' => null]));

    $post = read('app/Policies/PostPolicy.php');
    expect($post)
        ->toContain('return $user->getKey() === $post->user_id;')
        ->toContain('public function viewAny(User $user): bool')
        ->toContain('function restore(')
        ->toContain('function forceDelete(');

    $comment = read('app/Policies/CommentPolicy.php');
    expect($comment)
        ->toContain("public function update(User \$user, Comment \$comment): bool\n    {\n        return false;")
        ->toContain("public function view(User \$user, Comment \$comment): bool\n    {\n        return true;")
        ->not->toContain('function restore(');
});

it('locks abilities it cannot build, and says so once', function () {
    $this->build($this->draft(['Comment' => null]));

    $locked = array_filter($this->notices(), fn (string $n) => str_contains($n, 'CommentPolicy') && str_contains($n, 'locked'));

    expect($locked)->toHaveCount(1);
});

it('can remove abilities from the defaults with except', function () {
    $this->build($this->draft(['Post' => ['except' => ['forceDelete', 'delete']]]));

    expect(read('app/Policies/PostPolicy.php'))
        ->not->toContain('function forceDelete(')
        ->not->toContain('function delete(')
        ->toContain('function restore(');
});

it('accepts shorthand lists', function (array|string $policies) {
    $this->build($this->draft($policies));

    expect(file_exists(base_path('app/Policies/PostPolicy.php')))->toBeTrue()
        ->and(file_exists(base_path('app/Policies/CommentPolicy.php')))->toBeTrue()
        ->and(file_exists(base_path('app/Policies/TagPolicy.php')))->toBeFalse();
})->with([
    'list' => [['Post', 'Comment']],
    'comma separated' => ['Post, Comment'],
]);

it('honours auto_policies and per-model opt-out, naming a User model $model', function () {
    config(['blueprint_policies.auto_policies' => true]);
    $this->build($this->draft(['Post' => null, 'Tag' => false]));

    expect(file_exists(base_path('app/Policies/CommentPolicy.php')))->toBeTrue()
        ->and(file_exists(base_path('app/Policies/TagPolicy.php')))->toBeFalse()
        ->and(read('app/Policies/UserPolicy.php'))->toContain('public function view(User $user, User $model): bool');
});

it('puts a policy for a namespaced model in the matching sub-namespace', function () {
    $this->build($this->draft(['Admin/Post' => ['model' => 'Post']]));

    expect(read('app/Policies/Admin/PostPolicy.php'))->toContain('namespace App\Policies\Admin;');
});

it('rejects invalid draft options', function (array $policies, string $message) {
    expect(fn () => $this->build($this->draft($policies)))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown option' => [['Post' => ['bogus' => 1]], 'bogus'],
    'unknown rule term' => [['Post' => ['abilities' => ['view' => 'wat']]], 'Unknown rule term'],
    'owner rule without an owner column' => [['Tag' => ['abilities' => ['update' => 'owner']]], 'no owner column'],
    'owner: false with an owner rule' => [['Post' => ['owner' => false, 'abilities' => ['update' => 'owner']]], 'no owner column'],
    'owner column missing from the model' => [['Post' => ['owner' => 'nope']], 'not a column'],
    'state column missing' => [['Post' => ['abilities' => ['update' => 'state:nope=x']]], 'state column [nope]'],
    'owner on a class ability' => [['Post' => ['abilities' => ['viewAny' => 'owner']]], 'acts on the class'],
    'bad ability name' => [['Post' => ['abilities' => ['Bad_Name' => 'auth']]], 'not a valid ability name'],
    'before as an ability' => [['Post' => ['abilities' => ['before' => 'auth']]], 'policy hook'],
    'before using owner' => [['Post' => ['before' => 'owner']], 'cannot use owner or state'],
    'except with abilities' => [['Post' => ['except' => ['view'], 'abilities' => ['update' => 'auth']]], 'cannot be combined'],
    'empty abilities' => [['Post' => ['abilities' => []]], 'abilities'],
]);

it('refuses a policy that Blueprint is already generating from controller meta', function () {
    $draft = $this->draft(['Post' => null]);
    $draft['controllers']['Post']['meta'] = ['policies' => true];

    expect(fn () => $this->build($draft))->toThrow(InvalidArgumentException::class, 'declared twice');
});
