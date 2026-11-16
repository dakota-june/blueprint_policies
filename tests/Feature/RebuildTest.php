<?php

declare(strict_types=1);

it('is idempotent', function () {
    $this->build($this->draft());
    $first = snapshot();

    $this->build($this->draft(), ['policies']);

    expect(snapshot())->toBe($first);
});

it('keeps your code and follows the draft', function () {
    $this->build($this->draft());
    $path = base_path('app/Policies/PostPolicy.php');

    // Take ownership of view() by removing its tag, and add a method of your own.
    $source = file_get_contents($path);
    $source = str_replace(
        "    public function view(?User \$user, Post \$post): bool\n    {\n        return true;\n    }",
        "    public function view(?User \$user, Post \$post): bool\n    {\n        return \$post->status === 'published';\n    }",
        $source,
    );
    $source = preg_replace(
        '/\n     \* @generated blueprint_policies[^\n]*\n     \* Rule: public\n     \*\/\n    public function view\(/',
        "\n     * Mine now.\n     */\n    public function view(",
        $source,
        1,
    );
    $source = rtrim(substr($source, 0, -2)) . "\n\n    public function impersonate(User \$user): bool\n    {\n        return \$user->isSuperAdmin();\n    }\n}\n";
    file_put_contents($path, $source);

    $policies = $this->draft()['policies'];
    $policies['Post']['abilities']['update'] = 'owner';
    $policies['Post']['abilities']['view'] = 'auth';
    $policies['Post']['abilities']['archive'] = 'role:editor';
    unset($policies['Post']['abilities']['publish']);

    $this->notices();
    $this->build($this->draft($policies), ['policies']);
    $policy = file_get_contents($path);

    expect($policy)
        ->toContain('return $user->getKey() === $post->user_id;')
        ->toContain('public function archive(User $user, Post $post): bool')
        ->not->toContain('function publish(')
        ->toContain("return \$post->status === 'published';")
        ->toContain('public function impersonate(User $user): bool')
        ->and(lints($policy))->toBeTrue();

    $skipped = array_filter($this->notices(), fn (string $n) => str_contains($n, 'view') && str_contains($n, 'belongs to you'));
    expect($skipped)->toHaveCount(1);
});
