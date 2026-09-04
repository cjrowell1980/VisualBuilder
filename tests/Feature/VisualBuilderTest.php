<?php

namespace Tests\Feature;

use App\Contracts\ProcessRunner;
use App\Models\BuilderProject;
use App\Models\User;
use App\Services\Assembly\LaravelProjectAssembler;
use App\Services\Debugging\IterationValidator;
use App\Services\Debugging\PreviewServerManager;
use App\Services\Generation\LaravelArtifactGenerator;
use App\Services\Iterations\IterationCloner;
use App\Services\Packaging\IterationPackager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Native\Desktop\Facades\ChildProcess;
use Native\Desktop\Facades\Shell;
use Tests\TestCase;

class VisualBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_a_project_with_an_initial_iteration(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::projects.index')
            ->set('name', 'Customer Portal')
            ->set('description', 'Manage customer accounts')
            ->set('template', 'application-api')
            ->set('databaseDriver', 'pgsql')
            ->set('dockerEnabled', true)
            ->set('outputPath', 'C:\\Projects\\customer-portal')
            ->call('createProject')
            ->assertRedirect();

        $project = BuilderProject::firstOrFail();
        $this->assertSame($user->id, $project->user_id);
        $this->assertSame('customer-portal', $project->slug);
        $this->assertSame('application-api', $project->template);
        $this->assertTrue($project->docker_enabled);
        $this->assertSame('C:\\Projects\\customer-portal', $project->output_path);
        $this->assertDatabaseHas('build_iterations', [
            'builder_project_id' => $project->id,
            'number' => 1,
            'status' => 'draft',
        ]);
    }

    public function test_project_page_is_owner_scoped(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $project = $owner->builderProjects()->create(['name' => 'Private', 'slug' => 'private']);
        $project->iterations()->create(['number' => 1, 'name' => 'Initial build']);

        $this->actingAs($otherUser)->get(route('projects.show', $project))->assertForbidden();
    }

    public function test_generator_writes_model_migration_and_manifest(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $project = $user->builderProjects()->create(['name' => 'CRM', 'slug' => 'crm']);
        $iteration = $project->iterations()->create(['number' => 1, 'name' => 'Initial build']);
        $model = $iteration->models()->create(['name' => 'Customer', 'table_name' => 'customers']);
        $field = $model->fields()->create(['name' => 'email', 'label' => 'Email', 'type' => 'string', 'indexed' => true]);
        $page = $iteration->pages()->create([
            'model_definition_id' => $model->id,
            'name' => 'Customers',
            'slug' => 'customers',
            'page_type' => 'index',
        ]);
        $page->controls()->create([
            'model_field_id' => $field->id,
            'control_type' => 'input',
            'label' => 'Email address',
        ]);

        $result = app(LaravelArtifactGenerator::class)->generate($iteration);

        $this->assertContains('app/Models/Customer.php', $result['files']);
        Storage::disk('local')->assertExists('generated/crm/iteration-1/app/Models/Customer.php');
        Storage::disk('local')->assertExists('generated/crm/iteration-1/resources/views/pages/customers.blade.php');
        Storage::disk('local')->assertExists('generated/crm/iteration-1/routes/generated.php');
        Storage::disk('local')->assertExists('generated/crm/iteration-1/visual-builder.json');
        $this->assertSame('generated', $iteration->fresh()->status);

        app(IterationValidator::class)->run($iteration);
        $package = app(IterationPackager::class)->zip($iteration->fresh());
        $this->assertSame('zip', $package->format);
        $this->assertSame(64, strlen($package->checksum));
        $this->assertFileExists($package->path);
    }

    public function test_editor_creates_fields_relationships_pages_and_controls_within_the_project(): void
    {
        $user = User::factory()->create();
        $project = $user->builderProjects()->create(['name' => 'CRM', 'slug' => 'crm']);
        $iteration = $project->iterations()->create(['number' => 1, 'name' => 'Initial build']);
        $customer = $iteration->models()->create(['name' => 'Customer', 'table_name' => 'customers']);
        $contact = $iteration->models()->create(['name' => 'Contact', 'table_name' => 'contacts']);

        $component = Livewire::actingAs($user)
            ->test('pages::projects.show', ['project' => $project])
            ->set('selectedModelId', $contact->id)
            ->set('fieldName', 'email')
            ->set('fieldLabel', 'Email address')
            ->set('fieldType', 'string')
            ->set('fieldRules', 'required|email|max:255')
            ->set('fieldUnique', true)
            ->call('addField');

        $field = $contact->fields()->firstOrFail();
        $this->assertSame(['required', 'email', 'max:255'], $field->validation_rules);
        $this->assertTrue($field->unique);

        $component
            ->set('relationshipName', 'customer')
            ->set('relationshipType', 'belongsTo')
            ->set('relationshipTargetId', $customer->id)
            ->call('addRelationship')
            ->set('pageName', 'Contacts')
            ->set('pageSlug', 'contacts')
            ->set('pageType', 'index')
            ->set('pageModelId', $contact->id)
            ->call('addPage');

        $page = $iteration->pages()->firstOrFail();
        $component
            ->set('selectedPageId', $page->id)
            ->set('controlType', 'input')
            ->set('controlLabel', 'Email')
            ->set('controlFieldId', $field->id)
            ->call('addControl');

        $this->assertDatabaseHas('model_relationships', ['source_model_id' => $contact->id, 'target_model_id' => $customer->id]);
        $this->assertDatabaseHas('control_definitions', ['page_definition_id' => $page->id, 'model_field_id' => $field->id]);
    }

    public function test_new_iteration_clones_the_complete_editable_graph(): void
    {
        $user = User::factory()->create();
        $project = $user->builderProjects()->create(['name' => 'CRM', 'slug' => 'crm']);
        $source = $project->iterations()->create(['number' => 1, 'name' => 'Initial build']);
        $customer = $source->models()->create(['name' => 'Customer', 'table_name' => 'customers']);
        $contact = $source->models()->create(['name' => 'Contact', 'table_name' => 'contacts']);
        $field = $contact->fields()->create(['name' => 'email', 'label' => 'Email', 'type' => 'string']);
        $contact->relationships()->create([
            'target_model_id' => $customer->id,
            'name' => 'customer',
            'type' => 'belongsTo',
            'foreign_key' => 'customer_id',
        ]);
        $page = $source->pages()->create([
            'model_definition_id' => $contact->id,
            'name' => 'Contacts',
            'slug' => 'contacts',
            'page_type' => 'index',
        ]);
        $page->controls()->create(['model_field_id' => $field->id, 'control_type' => 'input', 'label' => 'Email']);
        $source->plugins()->create(['package' => 'spatie/laravel-permission', 'constraint' => '^7.0', 'approved' => true]);

        $copy = app(IterationCloner::class)->clone($source, 'Add approvals')->load('models.fields', 'models.relationships', 'pages.controls', 'plugins');

        $this->assertSame(2, $copy->number);
        $this->assertSame('Add approvals', $copy->name);
        $this->assertCount(2, $copy->models);
        $this->assertCount(1, $copy->models->firstWhere('name', 'Contact')->relationships);
        $this->assertCount(1, $copy->pages);
        $this->assertCount(1, $copy->pages->first()->controls);
        $this->assertCount(1, $copy->plugins);
        $this->assertNotSame($field->id, $copy->models->firstWhere('name', 'Contact')->fields->first()->id);
    }

    public function test_validation_run_blocks_incomplete_designs_and_passes_complete_ones(): void
    {
        $user = User::factory()->create();
        $project = $user->builderProjects()->create(['name' => 'CRM', 'slug' => 'crm']);
        $iteration = $project->iterations()->create(['number' => 1, 'name' => 'Initial build']);

        $failed = app(IterationValidator::class)->run($iteration);
        $this->assertSame('failed', $failed->status);

        $model = $iteration->models()->create(['name' => 'Customer', 'table_name' => 'customers']);
        $field = $model->fields()->create(['name' => 'name', 'label' => 'Name', 'type' => 'string']);
        $page = $iteration->pages()->create([
            'model_definition_id' => $model->id,
            'name' => 'Customers',
            'slug' => 'customers',
            'page_type' => 'index',
        ]);
        $page->controls()->create(['model_field_id' => $field->id, 'control_type' => 'input', 'label' => 'Name']);

        $passed = app(IterationValidator::class)->run($iteration);
        $this->assertSame('passed', $passed->status);
        $this->assertNotEmpty($passed->checks);
        $this->assertDatabaseCount('build_runs', 2);
    }

    public function test_assembler_creates_and_verifies_a_runnable_project_without_overwriting(): void
    {
        Storage::fake('local');
        $runner = new FakeProcessRunner;
        $this->app->instance(ProcessRunner::class, $runner);

        $user = User::factory()->create();
        $outputPath = Storage::disk('local')->path('assembled/customer-portal');
        mkdir(dirname($outputPath), recursive: true);
        $project = $user->builderProjects()->create([
            'name' => 'Customer Portal',
            'slug' => 'customer-portal',
            'database_driver' => 'sqlite',
            'output_path' => $outputPath,
        ]);
        $iteration = $project->iterations()->create(['number' => 1, 'name' => 'Initial build']);
        $model = $iteration->models()->create(['name' => 'Customer', 'table_name' => 'customers']);
        $field = $model->fields()->create(['name' => 'name', 'label' => 'Name', 'type' => 'string']);
        $page = $iteration->pages()->create([
            'model_definition_id' => $model->id,
            'name' => 'Customers',
            'slug' => 'customers',
            'page_type' => 'index',
        ]);
        $page->controls()->create(['model_field_id' => $field->id, 'control_type' => 'input', 'label' => 'Name']);
        $iteration->plugins()->create(['package' => 'spatie/laravel-permission', 'constraint' => '^7.0', 'approved' => true]);

        app(IterationValidator::class)->run($iteration);
        app(LaravelArtifactGenerator::class)->generate($iteration);
        $run = app(LaravelProjectAssembler::class)->assemble($iteration->fresh());

        $this->assertSame('passed', $run->status);
        $this->assertSame('assembled', $project->fresh()->status);
        $this->assertFileExists($outputPath.DIRECTORY_SEPARATOR.'visual-builder.json');
        $this->assertStringContainsString("require __DIR__.'/generated.php';", file_get_contents($outputPath.DIRECTORY_SEPARATOR.'routes'.DIRECTORY_SEPARATOR.'web.php'));
        $this->assertContains(['composer', 'require', 'spatie/laravel-permission:^7.0'], $runner->commands);
        $this->assertContains(['npm', 'run', 'build'], $runner->commands);

        $second = app(LaravelProjectAssembler::class)->assemble($iteration->fresh());
        $this->assertSame('failed', $second->status);
        $this->assertStringContainsString('already exists', $second->output);
    }

    public function test_preview_server_has_a_managed_native_lifecycle(): void
    {
        $processes = ChildProcess::fake();
        $shell = Shell::fake();
        $user = User::factory()->create();
        $outputPath = storage_path('framework/testing/preview-app');
        if (! is_dir($outputPath)) {
            mkdir($outputPath, recursive: true);
        }
        touch($outputPath.DIRECTORY_SEPARATOR.'artisan');
        $project = $user->builderProjects()->create([
            'name' => 'Preview App',
            'slug' => 'preview-app',
            'output_path' => $outputPath,
            'status' => 'assembled',
        ]);
        $iteration = $project->iterations()->create(['number' => 1, 'name' => 'Initial build']);
        $manager = app(PreviewServerManager::class);

        $run = $manager->start($iteration);
        $this->assertSame('running', $run->status);
        $processes->assertStarted(fn (array|string $cmd, string $alias, ?string $cwd, ?array $env, bool $persistent): bool => $alias === 'visual-builder-preview-'.$project->id
            && $cwd === $outputPath && is_array($cmd) && in_array('serve', $cmd, true));

        $manager->open($iteration);
        $shell->assertOpenedExternal('http://127.0.0.1:'.(8100 + ($project->id % 500)));

        $manager->stop($iteration);
        $processes->assertStop('visual-builder-preview-'.$project->id);
        $this->assertSame('stopped', $run->fresh()->status);
    }
}

final class FakeProcessRunner implements ProcessRunner
{
    /** @var list<list<string>> */
    public array $commands = [];

    public function run(array $command, string $workingDirectory, int $timeout = 600): array
    {
        $this->commands[] = $command;
        if ($command[0] === 'laravel') {
            $outputPath = $workingDirectory.DIRECTORY_SEPARATOR.$command[2];
            mkdir($outputPath.DIRECTORY_SEPARATOR.'routes', recursive: true);
            file_put_contents($outputPath.DIRECTORY_SEPARATOR.'routes'.DIRECTORY_SEPARATOR.'web.php', "<?php\n");
        }

        return ['successful' => true, 'output' => implode(' ', $command), 'exit_code' => 0];
    }
}
