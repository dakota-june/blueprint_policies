<?php

declare(strict_types=1);

return [
    /*
    | Namespace (relative to blueprint.namespace) of the generated policies: App\Policies by default.
    | Blueprint's own `blueprint.policy_namespace`, when set, wins so both generators agree.
    | Models in a sub-namespace get a policy in the same sub-namespace (App\Policies\Blog\PostPolicy).
    */
    'namespace' => 'Policies',

    /*
    | Generate a policy for every non-pivot model in the draft that is not listed under `policies:`.
    | Opt a model out with `Name: false`.
    */
    'auto_policies' => false,

    /*
    | The user class policies type-hint. null = auth.providers.users.model, else App\Models\User.
    */
    'user_model' => null,

    /*
    | Abilities generated when a policy does not list `abilities:`. restore / forceDelete are added only for
    | models that use soft deletes.
    */
    'default_abilities' => ['viewAny', 'view', 'create', 'update', 'delete'],
    'soft_delete_abilities' => ['restore', 'forceDelete'],

    /*
    | The rule an ability gets when the draft does not give one. See the README for the rule syntax.
    | Reads default to "any signed-in user"; writes default to the model's owner.
    */
    'default_rules' => [
        'viewAny' => 'auth',
        'view' => 'auth',
        'create' => 'auth',
        'update' => 'owner',
        'delete' => 'owner',
        'restore' => 'owner',
        'forceDelete' => 'owner',
    ],

    /*
    | Used instead of a rule that cannot be built, e.g. `owner` on a model with no owner column.
    | Keep it "never": a missing rule should lock the ability, not open it. A notice says what happened.
    */
    'fallback_rule' => 'never',

    /*
    | Columns tried, in order, to find who owns a record when the draft does not set `owner:`.
    | A belongsTo relationship to the user model is also recognised.
    */
    'owner_columns' => ['user_id', 'owner_id', 'author_id', 'created_by'],

    /*
    | A rule granted to every policy's before() hook (e.g. 'admin'). null = no before() unless the draft asks.
    */
    'before' => null,

    /*
    | PHP written for the user-dependent rule terms. {{ value }} is replaced with the quoted argument.
    | Defaults suit spatie/laravel-permission (role: hasRole, permission: can); change them for any other role system.
    */
    'checks' => [
        'role' => '$user->hasRole({{ value }})',
        'permission' => '$user->can({{ value }})',
        'admin' => '$user->hasRole(\'admin\')',
        'verified' => '$user->hasVerifiedEmail()',
    ],

    /*
    | Controller wiring: tagged check statements at the top of the standard resource actions.
    | style: 'gate'      Gate::authorize('update', $post);   (works in every controller)
    |        'authorize' $this->authorize('update', $post);  (needs the AuthorizesRequests trait)
    */
    'controllers' => [
        'wire' => true,
        'style' => 'gate',
    ],

    /*
    | How a policy is attached to its model when Laravel would not find it by naming convention.
    | 'auto'      #[UsePolicy] when your Laravel has it, otherwise Gate::policy() in the provider below
    | 'attribute' always #[UsePolicy]     'gate' always Gate::policy()     'none' never
    | Models Laravel does discover (App\Models\Post + App\Policies\PostPolicy) are never touched.
    */
    'registration' => 'auto',

    /*
    | The provider that receives Gate::policy() lines when registration needs them.
    */
    'provider' => 'app/Providers/AppServiceProvider.php',

    /*
    | Records what was generated so `blueprint:erase` can undo it safely. Relative to the project root.
    */
    'manifest' => '.blueprint_policies',
];
