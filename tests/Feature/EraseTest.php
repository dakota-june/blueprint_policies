<?php

declare(strict_types=1);

use Blueprint\Blueprint;
use Illuminate\Console\Events\CommandStarting;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

// Erase is triggered the way Blueprint's console does it: our listener runs on CommandStarting for blueprint:erase,
// just before the command deletes the files in `.blueprint`'s `created` list.

function eraseNow(): void
{
    event(new CommandStarting('blueprint:erase', new ArrayInput([]), new NullOutput()));
}

function writeBlueprintFile(array $created, array $updated = []): void
{
    file_put_contents(base_path('.blueprint'), app(Blueprint::class)->dump(['created' => $created, 'updated' => $updated]));
}

function createdList(): array
{
    return app(Blueprint::class)->parse(file_get_contents(base_path('.blueprint')), false)['created'];
}

it('takes $policy off the Nova resource and strips the Nova policy', function () {
    $this->writeNovaResources(['Post']);
    $original = read('app/Nova/Post.php');

    $this->build($this->novaDraft(['Post' => ['abilities' => ['view' => 'auth']]]));
    expect(read('app/Nova/Post.php'))->toContain('$policy');
    writeBlueprintFile(['app/Nova/Policies/PostPolicy.php'], ['app/Nova/Post.php']);

    eraseNow();

    expect(read('app/Nova/Post.php'))->toBe($original)
        ->and(read('app/Nova/Policies/PostPolicy.php'))->not->toContain('function view(')
        ->and(createdList())->toContain('app/Nova/Policies/PostPolicy.php')
        ->and(file_exists(base_path('.blueprint_policies')))->toBeFalse();
});

it('protects a Nova policy that has developer code in it', function () {
    $this->writeNovaResources(['Post']);
    $this->build($this->novaDraft(['Post' => ['abilities' => ['view' => 'auth']]]));
    writeBlueprintFile(['app/Nova/Policies/PostPolicy.php'], ['app/Nova/Post.php']);

    $path = base_path('app/Nova/Policies/PostPolicy.php');
    file_put_contents($path, preg_replace('/\}\s*$/', "\n    public function mine(): bool\n    {\n        return true;\n    }\n}\n", file_get_contents($path)));

    eraseNow();

    expect(createdList())->not->toContain('app/Nova/Policies/PostPolicy.php')
        ->and(read('app/Nova/Policies/PostPolicy.php'))->toContain('function mine(')->not->toContain('function view(')
        ->and(lints(read('app/Nova/Policies/PostPolicy.php')))->toBeTrue()
        ->and(glob(base_path('.blueprint.bak-*')))->toHaveCount(1);
});

it('erases Laravel policies and Nova policies for the same model independently', function () {
    $this->writeNovaResources(['Post']);
    $this->build($this->novaDraft(['Post' => null], ['Post' => null]));
    writeBlueprintFile(['app/Policies/PostPolicy.php', 'app/Nova/Policies/PostPolicy.php'], ['app/Nova/Post.php']);

    eraseNow();

    expect(read('app/Policies/PostPolicy.php'))->not->toContain('function ')
        ->and(read('app/Nova/Policies/PostPolicy.php'))->not->toContain('function ')
        ->and(read('app/Nova/Post.php'))->not->toContain('$policy')
        ->and(createdList())->toContain('app/Policies/PostPolicy.php', 'app/Nova/Policies/PostPolicy.php');
});
