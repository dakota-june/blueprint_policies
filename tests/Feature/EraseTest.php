<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandStarting;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

function erase(): void
{
    event(new CommandStarting('blueprint:erase', new ArrayInput([]), new NullOutput()));
}

function listed(): array
{
    return app(\Blueprint\Blueprint::class)->parse(file_get_contents(base_path('.blueprint')), false)['created'];
}

it('puts controllers back exactly and protects policies that hold your code', function () {
    $this->build($this->draft(), ['controllers']);
    $baseline = snapshot();

    $out = $this->build($this->draft());
    $created = [
        'app/Http/Controllers/PostController.php', 'app/Http/Controllers/CommentController.php',
        'app/Policies/PostPolicy.php', 'app/Policies/CommentPolicy.php', 'app/Policies/TagPolicy.php',
    ];
    file_put_contents(base_path('.blueprint'), app(\Blueprint\Blueprint::class)->dump(['created' => $created, 'updated' => []]));

    $tag = base_path('app/Policies/TagPolicy.php');
    file_put_contents($tag, rtrim(substr(file_get_contents($tag), 0, -2)) . "\n\n    public function sync(User \$user): bool\n    {\n        return true;\n    }\n}\n");

    erase();

    $after = snapshot();
    expect($after['app/Http/Controllers/PostController.php'])->toBe($baseline['app/Http/Controllers/PostController.php'])
        ->and($after['app/Http/Controllers/CommentController.php'])->toBe($baseline['app/Http/Controllers/CommentController.php']);

    expect(listed())->not->toContain('app/Policies/TagPolicy.php')
        ->toContain('app/Policies/PostPolicy.php', 'app/Policies/CommentPolicy.php');

    expect($after['app/Policies/TagPolicy.php'])->toContain('function sync(')->not->toContain('@generated')
        ->and(lints($after['app/Policies/TagPolicy.php']))->toBeTrue()
        ->and($after['app/Policies/PostPolicy.php'])->not->toContain('function ');

    expect(file_exists(base_path('.blueprint_policies')))->toBeFalse()
        ->and(glob(base_path('.blueprint.bak-*')))->toHaveCount(1);
});

it('removes #[UsePolicy] and Gate::policy() lines it added', function (string $mode) {
    config(['blueprint.policy_namespace' => null, 'blueprint_policies.namespace' => 'Auth', 'blueprint_policies.registration' => $mode]);
    $this->writeModels();
    $before = snapshot();

    $this->build($this->draft(), ['policies']);
    file_put_contents(base_path('.blueprint'), app(\Blueprint\Blueprint::class)->dump(['created' => ['app/Auth/PostPolicy.php'], 'updated' => []]));

    erase();

    $after = snapshot();
    expect($after['app/Models/Post.php'])->toBe($before['app/Models/Post.php'])
        ->and($after['app/Providers/PolicyTestProvider.php'])->toBe($before['app/Providers/PolicyTestProvider.php']);
})->with(['attribute', 'gate']);
