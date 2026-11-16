<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintPolicies;

use Blueprint\Blueprint;
use Dakotajune\BlueprintPolicies\Generators\PolicyGenerator;
use Dakotajune\BlueprintPolicies\Lexers\PolicyLexer;
use Dakotajune\BlueprintPolicies\Listeners\CleanUpOnErase;
use Dakotajune\BlueprintPolicies\Support\NoticeBag;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\ServiceProvider;

/**
 * Deliberately NOT a DeferrableProvider: Blueprint's own provider already defers Blueprint::class, and two
 * deferred providers cannot both own one service. boot() must also run so the erase listener is registered
 * before `blueprint:erase` starts.
 */
final class BlueprintPoliciesServiceProvider extends ServiceProvider
{
    /** Config keys that hold a map of settings, so a published file that sets one entry keeps the defaults of the rest. */
    private const MAPS = ['checks', 'controllers'];

    public function register(): void
    {
        $this->mergeConfig();

        $this->app->singleton(NoticeBag::class);

        // extend() runs when Blueprint is resolved, whatever order the providers loaded in.
        $this->app->extend(Blueprint::class, function (Blueprint $blueprint, $app): Blueprint {
            $blueprint->registerLexer($app->make(PolicyLexer::class));
            $blueprint->registerGenerator($app->make(PolicyGenerator::class));

            return $blueprint;
        });
    }

    public function boot(): void
    {
        $events = $this->app['events'];

        // Resolve the listener only when erase really starts, not for every artisan command.
        $events->listen(CommandStarting::class, function (CommandStarting $event): void {
            if ($event->command === 'blueprint:erase') {
                $this->app->make(CleanUpOnErase::class)->handle($event);
            }
        });

        $events->listen(CommandFinished::class, function (CommandFinished $event): void {
            if (! in_array($event->command, ['blueprint:build', 'blueprint:erase'], true)) {
                return;
            }

            foreach ($this->app->make(NoticeBag::class)->pull() as $notice) {
                $event->output->writeln("<comment>blueprint_policies:</comment> {$notice}");
            }
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/blueprint_policies.php' => config_path('blueprint_policies.php'),
            ], 'blueprint-policies-config');

            $this->publishes([
                __DIR__ . '/../stubs/policy.stub' => base_path('stubs/blueprint/blueprint-policy.stub'),
            ], 'blueprint-policies-stubs');

            $this->publishes([
                __DIR__ . '/../stubs/policy.stub' => base_path('stubs/blueprint/blueprint-nova-policy.stub'),
            ], 'blueprint-policies-nova-stubs');
        }
    }

    /**
     * Like mergeConfigFrom(), but one level deeper for the settings maps and the `nova` block. Laravel's own merge is
     * shallow, so a published config that overrode just `checks.role` would silently drop `admin` and `verified`.
     */
    private function mergeConfig(): void
    {
        if (method_exists($this->app, 'configurationIsCached') && $this->app->configurationIsCached()) {
            return;
        }

        $defaults = require __DIR__ . '/../config/blueprint_policies.php';
        $config = $this->app['config'];

        $config->set('blueprint_policies', $this->merge($defaults, (array) $config->get('blueprint_policies', [])));
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function merge(array $defaults, array $current): array
    {
        $merged = $this->mergeMaps($defaults, $current);

        if (is_array($defaults['nova'] ?? null) && is_array($current['nova'] ?? null)) {
            $merged['nova'] = $this->mergeMaps($defaults['nova'], $current['nova']);
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function mergeMaps(array $defaults, array $current): array
    {
        $merged = array_merge($defaults, $current);

        foreach (self::MAPS as $map) {
            if (is_array($defaults[$map] ?? null) && is_array($current[$map] ?? null)) {
                $merged[$map] = array_merge($defaults[$map], $current[$map]);
            }
        }

        return $merged;
    }
}
