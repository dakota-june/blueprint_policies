<?php

declare(strict_types=1);

it('leaves models and the provider alone when Laravel finds the policy by itself', function () {
    $this->writeModels();
    $before = snapshot();

    $this->build($this->draft(), ['policies']);

    $after = snapshot();
    expect($after['app/Models/Post.php'])->toBe($before['app/Models/Post.php'])
        ->and($after['app/Providers/PolicyTestProvider.php'])->toBe($before['app/Providers/PolicyTestProvider.php']);
});

it('adds #[UsePolicy] when the policy is somewhere Laravel would not look', function () {
    config(['blueprint.policy_namespace' => null, 'blueprint_policies.namespace' => 'Auth', 'blueprint_policies.registration' => 'attribute']);
    $this->writeModels();

    $this->build($this->draft(), ['policies']);
    $model = read('app/Models/Post.php');

    expect(file_exists(base_path('app/Auth/PostPolicy.php')))->toBeTrue()
        ->and($model)->toContain("#[UsePolicy(PostPolicy::class)]\nclass Post")
        ->toContain('use App\Auth\PostPolicy;')
        ->toContain('use Illuminate\Database\Eloquent\Attributes\UsePolicy;')
        ->and(lints($model))->toBeTrue();

    $snapshot = snapshot();
    $this->build($this->draft(), ['policies']);
    expect(snapshot())->toBe($snapshot);
});

it('adds Gate::policy() lines to the provider in gate mode', function () {
    config(['blueprint.policy_namespace' => null, 'blueprint_policies.namespace' => 'Auth', 'blueprint_policies.registration' => 'gate']);
    $this->writeModels();

    $this->build($this->draft(), ['policies']);
    $provider = read('app/Providers/PolicyTestProvider.php');

    expect($provider)
        ->toContain('Gate::policy(Post::class, PostPolicy::class); // @generated blueprint_policies')
        ->toContain('use Illuminate\Support\Facades\Gate;')
        ->and(lints($provider))->toBeTrue();

    $snapshot = snapshot();
    $this->build($this->draft(), ['policies']);
    expect(snapshot())->toBe($snapshot);
});

it('does not overwrite a different #[UsePolicy] the model already has', function () {
    config(['blueprint.policy_namespace' => null, 'blueprint_policies.namespace' => 'Auth', 'blueprint_policies.registration' => 'attribute']);
    $this->writeModels();
    $path = base_path('app/Models/Post.php');
    file_put_contents($path, str_replace(
        "class Post extends Model",
        "#[\\Illuminate\\Database\\Eloquent\\Attributes\\UsePolicy(\\App\\Other\\PostPolicy::class)]\nclass Post extends Model",
        file_get_contents($path),
    ));
    $before = file_get_contents($path);

    $this->build($this->draft(), ['policies']);

    expect(file_get_contents($path))->toBe($before);
    expect(array_filter($this->notices(), fn (string $n) => str_contains($n, 'already names a different policy')))->toHaveCount(1);
});
