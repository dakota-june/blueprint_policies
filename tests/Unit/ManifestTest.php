<?php

declare(strict_types=1);

use Dakotajune\BlueprintPolicies\Support\Manifest;

it('round-trips every section', function () {
    $manifest = new Manifest();
    $manifest->recordPolicy('app/Policies/PostPolicy.php', 'PostPolicy', true);
    $manifest->recordPolicy('app/Nova/Policies/PostPolicy.php', 'PostPolicy', false, 'nova');
    $manifest->recordController('app/Http/Controllers/PostController.php', 'PostController', ['A', 'A', 'B'], 'app/Policies/PostPolicy.php');
    $manifest->recordModel('app/Models/Post.php', 'Post', 'App\Auth\PostPolicy', ['X'], 'app/Policies/PostPolicy.php');
    $manifest->recordProvider('app/Providers/AppServiceProvider.php', 'AppServiceProvider', ['Gate'], ['app/Policies/PostPolicy.php']);
    $manifest->recordResource('app/Nova/Post.php', 'Post', 'App\Nova\Policies\PostPolicy', ['App\Nova\Policies\PostPolicy'], 'app/Nova/Policies/PostPolicy.php');

    $again = Manifest::fromJson($manifest->toJson());

    expect($again->policies())->toBe($manifest->policies())
        ->and($again->policy('app/Nova/Policies/PostPolicy.php'))->toBe(['class' => 'PostPolicy', 'created' => false, 'target' => 'nova'])
        ->and($again->controllers()['app/Http/Controllers/PostController.php']['uses'])->toBe(['A', 'B'])
        ->and($again->controllers()['app/Http/Controllers/PostController.php']['owner'])->toBe('app/Policies/PostPolicy.php')
        ->and($again->models())->toBe($manifest->models())
        ->and($again->providers())->toBe($manifest->providers())
        ->and($again->resources())->toBe($manifest->resources())
        ->and($again->model('app/Models/Post.php')['policy'])->toBe('App\Auth\PostPolicy')
        ->and($again->isEmpty())->toBeFalse();
});

it('reads a manifest written before targets, owners and resources existed', function () {
    $old = '{"policies":{"app/Policies/PostPolicy.php":{"class":"PostPolicy","created":true}},"controllers":{},"models":{},"providers":{}}';
    $manifest = Manifest::fromJson($old);

    expect($manifest->policy('app/Policies/PostPolicy.php')['created'])->toBeTrue()
        ->and($manifest->resources())->toBe([])
        ->and($manifest->isEmpty())->toBeFalse();
});

it('is empty when nothing was recorded, and survives unreadable input', function () {
    expect((new Manifest())->isEmpty())->toBeTrue()
        ->and(Manifest::fromJson(null)->isEmpty())->toBeTrue()
        ->and(Manifest::fromJson('not json')->isEmpty())->toBeTrue();
});

it('adopts an entry from an earlier manifest unchanged', function () {
    $previous = new Manifest();
    $previous->recordResource('app/Nova/Post.php', 'Post', 'P', ['P'], 'owner');

    $next = new Manifest();
    expect($next->has(Manifest::RESOURCES, 'app/Nova/Post.php'))->toBeFalse();

    $next->adopt(Manifest::RESOURCES, 'app/Nova/Post.php', $previous->resources()['app/Nova/Post.php']);

    expect($next->has(Manifest::RESOURCES, 'app/Nova/Post.php'))->toBeTrue()
        ->and($next->resources())->toBe($previous->resources());
});
