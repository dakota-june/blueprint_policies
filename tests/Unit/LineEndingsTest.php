<?php

declare(strict_types=1);

use Dakotajune\BlueprintPolicies\Support\PolicyAttributeInjector;
use Dakotajune\BlueprintPolicies\Support\PolicyClassEditor;
use Dakotajune\BlueprintPolicies\Support\PolicyMemberBuilder;
use Dakotajune\BlueprintPolicies\Support\ResourcePolicyInjector;
use Dakotajune\BlueprintPolicies\Support\StatementEditor;

// Windows checkouts commonly hold CRLF files. Every editor must keep them CRLF, never mix endings,
// and above all never lose code because a "\n" it was looking for was really "\r\n".

function crlf(string $source): string
{
    // Start from LF whatever the test file's own line endings are (a Windows checkout may hold it as CRLF).
    return str_replace("\n", "\r\n", str_replace("\r\n", "\n", $source));
}

function hasBareLf(string $source): bool
{
    return (bool) preg_match('/(?<!\r)\n/', $source);
}

function crlfController(): string
{
    return crlf(<<<'PHP'
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

    public function destroy(Request $request, Post $post)
    {
    }
}

PHP);
}

it('adds and strips controller statements in a CRLF file without losing code', function () {
    $editor = new StatementEditor();
    $gate = "{{ use:Illuminate\\Support\\Facades\\Gate }}::authorize('viewAny', {{ use:App\\Models\\Post }}::class);";
    $source = crlfController();

    $wired = $editor->insert($source, 'PostController', 'index', [$gate]);

    expect($wired->changed)->toBeTrue()
        ->and($wired->source)->toContain('$posts = Post::all();')->toContain("return view('post.index', compact('posts'));")
        ->and(hasBareLf($wired->source))->toBeFalse()
        ->and(lints($wired->source))->toBeTrue()
        ->and($editor->insert($wired->source, 'PostController', 'index', [$gate])->changed)->toBeFalse()
        ->and($editor->strip($wired->source, ['Illuminate\Support\Facades\Gate']))->toBe($source);
});

it('fills an empty method body in a CRLF file', function () {
    $result = (new StatementEditor())->insert(crlfController(), 'PostController', 'destroy', ["Gate::authorize('delete', \$post);"], 'post');

    expect($result->source)->toContain("Gate::authorize('delete', \$post); // @generated blueprint_policies")
        ->and(hasBareLf($result->source))->toBeFalse()
        ->and(lints($result->source))->toBeTrue();
});

it('writes, keeps and strips policy methods in a CRLF file', function () {
    $editor = new PolicyClassEditor();
    $method = (new PolicyMemberBuilder())->ability('view', 'App\Models\User', 'App\Models\Post', 'post', false, 'true', 'auth', 'post', 'posts');
    $source = crlf("<?php\n\nnamespace App\\Policies;\n\nclass PostPolicy\n{\n}\n");

    $built = $editor->update($source, 'PostPolicy', [], ['view' => $method]);
    $again = $editor->update($built->source, 'PostPolicy', [], ['view' => $method]);
    $stripped = $editor->stripGenerated($built->source, 'PostPolicy');

    expect($built->changed)->toBeTrue()
        ->and(hasBareLf($built->source))->toBeFalse()
        ->and(lints($built->source))->toBeTrue()
        ->and($again->changed)->toBeFalse()
        ->and($stripped)->not->toContain('function view(')
        ->and(hasBareLf($stripped))->toBeFalse()
        ->and($editor->hasDeveloperContent($stripped, 'PostPolicy'))->toBeFalse();
});

it('adds and removes #[UsePolicy] in a CRLF file', function () {
    $injector = new PolicyAttributeInjector();
    $model = crlf("<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass Post extends Model\n{\n}\n");

    $result = $injector->inject($model, 'Post', 'App\Auth\PostPolicy');

    expect(hasBareLf($result->source))->toBeFalse()
        ->and(lints($result->source))->toBeTrue()
        ->and($injector->remove($result->source, 'Post', 'App\Auth\PostPolicy', $result->uses))->toBe($model);
});

it('sets and removes a resource $policy in a CRLF file', function () {
    $injector = new ResourcePolicyInjector();
    $resource = crlf("<?php\n\nnamespace App\\Nova;\n\nuse Laravel\\Nova\\Resource;\n\nclass Post extends Resource\n{\n    public static \$title = 'id';\n}\n");

    $result = $injector->inject($resource, 'Post', 'App\Nova\Policies\PostPolicy');

    expect(hasBareLf($result->source))->toBeFalse()
        ->and(lints($result->source))->toBeTrue()
        ->and($injector->inject($result->source, 'Post', 'App\Nova\Policies\PostPolicy')->source)->toBe($result->source)
        ->and($injector->remove($result->source, 'Post', $result->uses))->toBe($resource);
});

// Blueprint on Windows writes a controller whose stub lines are CRLF and whose generated lines are LF.
function mixedController(): string
{
    $lines = explode("\n", rtrim(str_replace("\r\n", "\n", crlfController())) . "\n");   // plain LF text
    $out = '';

    foreach ($lines as $i => $line) {
        $out .= $line . ($i === count($lines) - 1 ? '' : match ($i % 3) { 0 => "\n", 1 => "\r\n", default => "\r\r\n" });
    }

    return $out;
}

it('gives a file with mixed line endings back byte for byte after strip()', function () {
    $editor = new StatementEditor();
    $gate = "{{ use:Illuminate\\Support\\Facades\\Gate }}::authorize('viewAny', {{ use:App\\Models\\Post }}::class);";
    $source = mixedController();

    expect(hasBareLf($source) && str_contains($source, "\r\n"))->toBeTrue();

    $wired = $editor->insert($source, 'PostController', 'index', [$gate]);

    expect($wired->changed)->toBeTrue()
        ->and(str_replace("\r\n", "\n", $wired->source))->toContain('@generated blueprint_policies')
        ->and(lints($wired->source))->toBeTrue()
        ->and($editor->insert($wired->source, 'PostController', 'index', [$gate])->changed)->toBeFalse()
        ->and($editor->strip($wired->source, ['Illuminate\Support\Facades\Gate']))->toBe($source);
});

it('keeps the endings of untouched lines when it edits a mixed file', function () {
    $source = mixedController();
    $wired = (new StatementEditor())->insert($source, 'PostController', 'destroy', ["Gate::authorize('delete', \$post);"], 'post');

    // Everything except the new line and the import is byte-identical to the original, line ending included.
    $kept = preg_replace('/^.*@generated blueprint_policies\R/m', '', $wired->source);
    $kept = preg_replace('/^use [^\r\n]*Gate;\R/m', '', $kept);

    expect($kept)->toBe($source);
});
