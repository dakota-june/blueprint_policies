<?php

declare(strict_types=1);

use Dakotajune\BlueprintPolicies\Support\TargetConfig;

it('reads the top-level keys for the laravel target', function () {
    config(['blueprint_policies.fallback_rule' => 'never']);

    expect(TargetConfig::get(TargetConfig::LARAVEL, 'fallback_rule'))->toBe('never')
        ->and(TargetConfig::get(TargetConfig::LARAVEL, 'namespace'))->toBe('Policies')
        ->and(TargetConfig::get(TargetConfig::LARAVEL, 'no_such_key', 'fallback'))->toBe('fallback')
        ->and(TargetConfig::get(TargetConfig::LARAVEL, 'controllers.wire'))->toBeTrue();
});

it('gives the nova target its own namespace, resources, registration and no controller wiring', function () {
    expect(TargetConfig::get(TargetConfig::NOVA, 'namespace'))->toBe('Nova\\Policies')
        ->and(TargetConfig::get(TargetConfig::NOVA, 'resource_namespace'))->toBe('Nova')
        ->and(TargetConfig::get(TargetConfig::NOVA, 'registration'))->toBe('resource')
        ->and(TargetConfig::get(TargetConfig::NOVA, 'controllers.wire'))->toBeFalse()
        ->and(TargetConfig::get(TargetConfig::NOVA, 'auto_policies'))->toBeFalse();
});

it('falls back to the top-level value for anything the nova block does not set', function () {
    config(['blueprint_policies.fallback_rule' => 'auth', 'blueprint_policies.owner_columns' => ['x_id']]);

    expect(TargetConfig::get(TargetConfig::NOVA, 'fallback_rule'))->toBe('auth')
        ->and(TargetConfig::get(TargetConfig::NOVA, 'owner_columns'))->toBe(['x_id']);
});

it('lets the nova block win, even with false or null', function () {
    config(['blueprint_policies.before' => 'admin']);

    expect(TargetConfig::get(TargetConfig::NOVA, 'before'))->toBe('admin');

    config(['blueprint_policies.nova.before' => false]);
    expect(TargetConfig::get(TargetConfig::NOVA, 'before'))->toBeFalse()
        ->and(TargetConfig::get(TargetConfig::LARAVEL, 'before'))->toBe('admin');

    config(['blueprint_policies.nova.before' => null]);
    expect(TargetConfig::get(TargetConfig::NOVA, 'before'))->toBeNull();
});

it('keeps the two auto_policies switches apart', function () {
    config(['blueprint_policies.auto_policies' => true]);

    expect(TargetConfig::get(TargetConfig::LARAVEL, 'auto_policies'))->toBeTrue()
        ->and(TargetConfig::get(TargetConfig::NOVA, 'auto_policies'))->toBeFalse();
});

it('merges nova checks over the top-level ones', function () {
    config(['blueprint_policies.nova.checks' => ['admin' => '$user->isNovaAdmin()']]);

    $nova = TargetConfig::checks(TargetConfig::NOVA);

    expect($nova['admin'])->toBe('$user->isNovaAdmin()')
        ->and($nova['role'])->toBe('$user->hasRole({{ value }})')
        ->and(TargetConfig::checks(TargetConfig::LARAVEL)['admin'])->toBe('$user->hasRole(\'admin\')');
});
