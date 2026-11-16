<?php

declare(strict_types=1);

// See the note in NovaPolicyGenerationTest: these need a real Laravel/Blueprint install to run.

it('keeps `auth` a real check where guests can reach the method', function () {
    $this->build($this->draft(['Post' => ['abilities' => ['update' => 'auth & state:status=published | public & role:x']]]));

    expect(preg_replace('/\s+/', ' ', read('app/Policies/PostPolicy.php')))
        ->toContain("(\$user !== null && \$post->status === 'published') || \$user !== null && \$user->hasRole('x')");
});

it('opts a policy out of a global before with `before: false`, and refuses `before: public`', function () {
    config(['blueprint_policies.before' => 'admin']);
    $this->build($this->draft([
        'Post' => ['before' => false, 'abilities' => ['view' => 'auth']],
        'Comment' => ['abilities' => ['view' => 'auth']],
    ]));

    expect(read('app/Policies/PostPolicy.php'))->not->toContain('function before(')
        ->and(read('app/Policies/CommentPolicy.php'))->toContain('function before(');

    expect(fn () => $this->build($this->draft(['Post' => ['before' => 'public']])))
        ->toThrow(InvalidArgumentException::class, 'grant every ability to everyone');
});

it('wires a User controller with $user while the policy keeps $model', function () {
    $this->build(['models' => ['User' => ['name' => 'string']], 'controllers' => ['User' => ['resource' => 'web']], 'policies' => ['User' => ['abilities' => ['view' => 'auth']]]]);

    expect(read('app/Http/Controllers/UserController.php'))->toContain("Gate::authorize('view', \$user);")
        ->and(read('app/Policies/UserPolicy.php'))->toContain('User $user, User $model');
});

it('takes controller wiring back when it is switched off, byte for byte', function () {
    $controllers = ['controllers' => ['Post' => ['resource' => 'web']]];
    $this->build($controllers + $this->draft([]), ['controllers']);
    $original = read('app/Http/Controllers/PostController.php');

    $draft = $controllers + $this->draft(['Post' => ['abilities' => ['view' => 'auth']]]);
    $this->build($draft, ['policies']);
    expect(read('app/Http/Controllers/PostController.php'))->toContain('@generated blueprint_policies');

    $draft['policies']['Post']['controller'] = false;
    $this->build($draft, ['policies']);

    expect(read('app/Http/Controllers/PostController.php'))->toBe($original);
});

it('keeps the edits of a policy that is missing from a later draft', function () {
    $controllers = ['controllers' => ['Post' => ['resource' => 'web']]];
    $this->build($controllers + $this->draft(['Post' => ['abilities' => ['view' => 'auth']], 'Tag' => null]));
    $wired = read('app/Http/Controllers/PostController.php');

    $this->build($controllers + $this->draft(['Tag' => null]), ['policies']);

    expect(read('app/Http/Controllers/PostController.php'))->toBe($wired);
});

it('does not record a #[UsePolicy] the developer wrote as its own', function () {
    $this->writeModels();
    config(['blueprint_policies.namespace' => 'Auth', 'blueprint.policy_namespace' => null, 'blueprint_policies.registration' => 'attribute']);
    $own = "<?php\n\nnamespace App\\Models;\n\nuse App\\Auth\\PostPolicy;\nuse Illuminate\\Database\\Eloquent\\Attributes\\UsePolicy;\nuse Illuminate\\Database\\Eloquent\\Model;\n\n#[UsePolicy(PostPolicy::class)]\nclass Post extends Model\n{\n}\n";
    file_put_contents(base_path('app/Models/Post.php'), $own);

    $this->build($this->draft(['Post' => null]), ['policies']);
    $this->build($this->draft(['Post' => null]), ['policies']);

    expect(read('app/Models/Post.php'))->toBe($own)
        ->and(file_exists(base_path('.blueprint_policies')) ? read('.blueprint_policies') : '')->not->toContain('app/Models/Post.php');
});

it('keeps a CRLF controller intact and CRLF', function () {
    $this->build(['controllers' => ['Post' => ['resource' => 'web']]] + $this->draft([]), ['controllers']);
    $crlf = str_replace("\n", "\r\n", read('app/Http/Controllers/PostController.php'));
    file_put_contents(base_path('app/Http/Controllers/PostController.php'), $crlf);

    $draft = ['controllers' => ['Post' => ['resource' => 'web']]] + $this->draft(['Post' => ['abilities' => ['view' => 'auth']]]);
    $this->build($draft, ['policies']);

    $edited = read('app/Http/Controllers/PostController.php');
    expect($edited)->toContain('@generated blueprint_policies')
        ->and(preg_match('/(?<!\r)\n/', $edited))->toBe(0)
        ->and(lints($edited))->toBeTrue();

    $draft['policies']['Post']['controller'] = false;
    $this->build($draft, ['policies']);

    expect(read('app/Http/Controllers/PostController.php'))->toBe($crlf);
});
