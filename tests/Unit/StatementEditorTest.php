<?php

declare(strict_types=1);

use Dakotajune\BlueprintPolicies\Support\StatementEditor;

const CONTROLLER = <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;

class PostController extends Controller
{
    public function index(Request $request)
    {
        $posts = Post::all();

        return view('post.index', compact('posts'));
    }

    public function show(Request $request, Post $post)
    {
        return view('post.show', compact('post'));
    }

    public function destroy(Request $request, Post $post)
    {
    }
}

PHP;

const GATE_INDEX = "{{ use:Illuminate\\Support\\Facades\\Gate }}::authorize('viewAny', {{ use:App\\Models\\Post }}::class);";

it('adds a tagged statement first, with its import', function () {
    $result = (new StatementEditor())->insert(CONTROLLER, 'PostController', 'index', [GATE_INDEX]);

    expect($result->changed)->toBeTrue()
        ->and($result->source)->toContain("    {\n        Gate::authorize('viewAny', Post::class); // @generated blueprint_policies\n        \$posts = Post::all();")
        ->and(substr_count($result->source, 'use Illuminate\Support\Facades\Gate;'))->toBe(1)
        ->and(substr_count($result->source, 'use App\Models\Post;'))->toBe(1)
        ->and(lints($result->source))->toBeTrue();
});

it('is idempotent and replaces only its own line', function () {
    $editor = new StatementEditor();
    $first = $editor->insert(CONTROLLER, 'PostController', 'index', [GATE_INDEX]);
    $again = $editor->insert($first->source, 'PostController', 'index', [GATE_INDEX]);
    $other = $editor->insert($first->source, 'PostController', 'index', ["Gate::authorize('create', Post::class);"]);

    expect($again->changed)->toBeFalse()->and($again->source)->toBe($first->source)
        ->and(substr_count($other->source, '@generated'))->toBe(1)
        ->and($other->source)->toContain("'create'")->not->toContain("'viewAny'")->toContain('$posts = Post::all();');
});

it('fills an empty method body', function () {
    $result = (new StatementEditor())->insert(CONTROLLER, 'PostController', 'destroy', ["Gate::authorize('delete', \$post);"], 'post');

    expect($result->source)->toContain("    {\n        Gate::authorize('delete', \$post); // @generated blueprint_policies\n    }")
        ->and(lints($result->source))->toBeTrue();
});

it('skips a method that lacks the parameter, has no body, or already authorizes', function () {
    $editor = new StatementEditor();

    $noParameter = $editor->insert(CONTROLLER, 'PostController', 'index', ["Gate::authorize('view', \$post);"], 'post');
    $missing = $editor->insert(CONTROLLER, 'PostController', 'nope', ['x();']);
    $own = $editor->insert(
        str_replace('$posts = Post::all();', "\$this->authorize('viewAny', Post::class);\n        \$posts = Post::all();", CONTROLLER),
        'PostController',
        'index',
        ["Gate::authorize('viewAny', Post::class);"],
        null,
        '/authorize\(/',
    );

    expect($noParameter->changed)->toBeFalse()->and($noParameter->notices)->toHaveCount(1)
        ->and($missing->changed)->toBeFalse()->and($missing->notices)->toHaveCount(1)
        ->and($own->changed)->toBeFalse()->and($own->notices)->toHaveCount(1);
});

it('strip() restores the original byte for byte', function () {
    $editor = new StatementEditor();
    $wired = $editor->insert(CONTROLLER, 'PostController', 'index', [GATE_INDEX])->source;
    $wired = $editor->insert($wired, 'PostController', 'show', ["Gate::authorize('view', \$post);"], 'post')->source;

    expect($editor->strip($wired, ['Illuminate\Support\Facades\Gate']))->toBe(CONTROLLER);
});

it('uses a leading backslash when the short name is taken', function () {
    $clash = str_replace('use Illuminate\Http\Request;', "use Illuminate\\Http\\Request;\nuse App\\Support\\Gate;", CONTROLLER);
    $result = (new StatementEditor())->insert($clash, 'PostController', 'index', [GATE_INDEX]);

    expect($result->source)->toContain('\Illuminate\Support\Facades\Gate::authorize')->and(lints($result->source))->toBeTrue();
});
