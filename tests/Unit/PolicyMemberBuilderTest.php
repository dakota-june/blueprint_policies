<?php

declare(strict_types=1);

use Dakotajune\BlueprintPolicies\Support\PolicyMemberBuilder;

it('cannot be talked out of its docblock by the rule text', function () {
    $builder = new PolicyMemberBuilder();
    $nasty = "role:x*/ \n phpinfo(); /*";

    foreach ([
        $builder->ability('update', 'App\Models\User', 'App\Models\Post', 'post', false, 'false', $nasty, 'post', 'posts'),
        $builder->before('App\Models\User', 'false', $nasty),
    ] as $member) {
        // The editor turns `{{ use:Fqcn }}` into a class name; do the same so the member is plain PHP.
        $php = preg_replace_callback('/\{\{ use:([\w\\\\]+) \}\}/', static fn (array $m): string => '\\' . $m[1], $member);

        expect($member)->toContain('* Rule: role:x* / phpinfo(); /*')
            ->and(lints("<?php\nclass P\n{\n{$php}\n}\n"))->toBeTrue();
    }
});

it('types the second argument with whatever class it is given', function () {
    $member = (new PolicyMemberBuilder())->ability('view', 'App\Models\User', 'App\Nova\Post', 'post', false, 'true', 'auth', 'post', 'posts');

    expect($member)->toContain('public function view({{ use:App\Models\User }} $user, {{ use:App\Nova\Post }} $post): bool');
});
