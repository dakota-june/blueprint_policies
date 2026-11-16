<?php

declare(strict_types=1);

use Dakotajune\BlueprintPolicies\Support\InjectionResult;
use Dakotajune\BlueprintPolicies\Support\PolicyAttributeInjector;

const MODEL = <<<'PHP'
<?php

namespace App\Models\Blog;

use Illuminate\Database\Eloquent\Model;

/**
 * A post.
 */
final class Post extends Model
{
}

PHP;

const POLICY = 'App\Policies\Blog\PostPolicy';

it('adds #[UsePolicy] after the docblock and imports both classes', function () {
    $result = (new PolicyAttributeInjector())->inject(MODEL, 'Post', POLICY);

    expect($result->status)->toBe(InjectionResult::INSERTED)
        ->and($result->source)->toContain(" */\n#[UsePolicy(PostPolicy::class)]\nfinal class Post")
        ->toContain('use Illuminate\Database\Eloquent\Attributes\UsePolicy;')
        ->toContain('use App\Policies\Blog\PostPolicy;')
        ->and(lints($result->source))->toBeTrue();
});

it('is idempotent', function () {
    $injector = new PolicyAttributeInjector();
    $first = $injector->inject(MODEL, 'Post', POLICY);
    $again = $injector->inject($first->source, 'Post', POLICY);

    expect($again->status)->toBe(InjectionResult::UNCHANGED)->and($again->source)->toBe($first->source);
});

it('leaves a different policy alone, even one with the same short name', function () {
    $injector = new PolicyAttributeInjector();
    $first = $injector->inject(MODEL, 'Post', POLICY);
    $other = $injector->inject($first->source, 'Post', 'App\Other\PostPolicy');

    expect($other->status)->toBe(InjectionResult::CONFLICT)->and($other->source)->toBe($first->source)
        ->and($injector->remove($first->source, 'Post', 'App\Other\PostPolicy', $first->uses))->toBe($first->source);
});

it('removes exactly what it added', function () {
    $injector = new PolicyAttributeInjector();
    $result = $injector->inject(MODEL, 'Post', POLICY);

    expect($injector->remove($result->source, 'Post', POLICY, $result->uses))->toBe(MODEL);
});

it('coexists with other attributes', function () {
    $withAttribute = str_replace('final class Post', "#[ObservedBy(PostObserver::class)]\nfinal class Post", MODEL);
    $result = (new PolicyAttributeInjector())->inject($withAttribute, 'Post', POLICY);

    expect($result->status)->toBe(InjectionResult::INSERTED)
        ->and($result->source)->toContain('#[ObservedBy(PostObserver::class)]')
        ->and(lints($result->source))->toBeTrue();
});
