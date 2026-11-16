<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintPolicies\Tests;

use Blueprint\Blueprint;
use Blueprint\BlueprintServiceProvider;
use Dakotajune\BlueprintPolicies\BlueprintPoliciesServiceProvider;
use Dakotajune\BlueprintPolicies\Support\NoticeBag;
use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    private string $originalDirectory = '';

    protected function getPackageProviders($app): array
    {
        return [BlueprintServiceProvider::class, BlueprintPoliciesServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Blueprint's own generators write to paths relative to the working directory; ours use base_path().
        // Make them the same place, or controllers would land in the package folder.
        $this->originalDirectory = (string) getcwd();
        chdir(base_path());

        $this->clean();

        config([
            'blueprint.policy_namespace' => 'Policies',
            // Testbench's default user model is Illuminate\Foundation\Auth\User; pin the usual app one.
            'blueprint_policies.user_model' => 'App\\Models\\User',
            'blueprint_policies.provider' => 'app/Providers/PolicyTestProvider.php',
        ]);
    }

    protected function tearDown(): void
    {
        $this->clean();
        chdir($this->originalDirectory);

        parent::tearDown();
    }

    /**
     * Removes what the tests generate and nothing else: Testbench's skeleton keeps its own files under app/
     * (the base Controller, the User model ...), and those must survive a run.
     */
    private function clean(): void
    {
        $files = new Filesystem();

        foreach (['app/Policies', 'app/Auth', 'app/Nova', 'app/Http/Controllers/Admin'] as $directory) {
            $files->deleteDirectory(base_path($directory));
        }

        foreach (['Post', 'Comment', 'Tag', 'User'] as $name) {
            @unlink(base_path("app/Http/Controllers/{$name}Controller.php"));

            foreach (glob(base_path("app/Http/Requests/{$name}*Request.php")) ?: [] as $request) {
                @unlink($request);
            }
        }

        foreach (['Post', 'Comment', 'Tag'] as $model) {
            @unlink(base_path("app/Models/{$model}.php"));
        }

        @unlink(base_path('app/Providers/PolicyTestProvider.php'));
        @unlink(base_path('.blueprint_policies'));
        @unlink(base_path('.blueprint'));

        foreach (glob(base_path('.blueprint.bak-*')) ?: [] as $backup) {
            @unlink($backup);
        }

        $this->app->make(NoticeBag::class)->pull();
    }

    /**
     * Runs real Blueprint lexers over an already-parsed draft, then the generators of the given types.
     *
     * @param  array<int, string>  $only  'controllers' = Blueprint's controller generator, 'policies' = ours
     * @return array{created?: array<int, mixed>, updated?: array<int, mixed>}
     */
    protected function build(array $draft, array $only = ['controllers', 'policies']): array
    {
        $blueprint = $this->app->make(Blueprint::class);

        return $blueprint->generate($blueprint->analyze($draft), $only);
    }

    /** @return array<int, string> */
    protected function notices(): array
    {
        return $this->app->make(NoticeBag::class)->pull();
    }

    /** @param  array<string, mixed>|string|null  $policies  replaces the fixture's `policies:` section when given */
    protected function draft(array|string|null $policies = null): array
    {
        $draft = require __DIR__ . '/fixtures/draft.php';

        if ($policies !== null) {
            $draft['policies'] = $policies;
        }

        return $draft;
    }

    /**
     * The fixture draft with a `nova_policies:` section.
     *
     * @param  array<string, mixed>|string  $nova  the `nova_policies:` section
     * @param  array<string, mixed>|string  $policies  the `policies:` section; empty by default so a test sees only Nova output
     */
    protected function novaDraft(array|string $nova, array|string $policies = []): array
    {
        $draft = $this->draft($policies);
        $draft['nova_policies'] = $nova;

        return $draft;
    }

    /** Minimal model files, for tests that register policies on models. */
    protected function writeModels(): void
    {
        @mkdir(base_path('app/Models'), 0777, true);

        foreach (['Post', 'Comment', 'Tag'] as $model) {
            file_put_contents(base_path("app/Models/{$model}.php"), "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass {$model} extends Model\n{\n}\n");
        }

        @mkdir(base_path('app/Providers'), 0777, true);
        file_put_contents(base_path('app/Providers/PolicyTestProvider.php'), "<?php\n\nnamespace App\\Providers;\n\nuse Illuminate\\Support\\ServiceProvider;\n\nclass PolicyTestProvider extends ServiceProvider\n{\n    public function register(): void\n    {\n    }\n\n    public function boot(): void\n    {\n        //\n    }\n}\n");
    }

    /**
     * Minimal Nova resource files (app/Nova/Post.php ...), for tests that register policies on resources.
     *
     * @param  array<int, string>  $names
     */
    protected function writeNovaResources(array $names = ['Post', 'Comment', 'Tag']): void
    {
        @mkdir(base_path('app/Nova'), 0777, true);

        foreach ($names as $name) {
            file_put_contents(
                base_path("app/Nova/{$name}.php"),
                "<?php\n\nnamespace App\\Nova;\n\nuse Laravel\\Nova\\Resource;\n\nclass {$name} extends Resource\n{\n    public static \$model = \\App\\Models\\{$name}::class;\n\n    public static \$title = 'id';\n}\n",
            );
        }
    }
}
