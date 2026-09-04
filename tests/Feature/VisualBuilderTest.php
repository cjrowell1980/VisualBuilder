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
use App\Services\Publishing\GitHubPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Native\Desktop\Facades\ChildProcess;
use Native\Desktop\Facades\Shell;
use Symfony\Component\Process\Process;
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
        $project = $user->builderProjects()->create(['name' => 'CRM', 'slug' => 'crm', 'template' => 'application-api', 'docker_enabled' => true]);
        $iteration = $project->iterations()->create(['number' => 1, 'name' => 'Initial build']);
        $model = $iteration->models()->create(['name' => 'Customer', 'table_name' => 'customers']);
        $field = $model->fields()->create(['name' => 'email', 'label' => 'Email', 'type' => 'string', 'indexed' => true, 'unique' => true, 'default_value' => 'unknown@example.com']);
        $model->relationships()->create([
            'target_model_id' => $model->id,
            'name' => 'parent',
            'type' => 'belongsTo',
            'foreign_key' => 'parent_id',
        ]);
        $role = $iteration->models()->create(['name' => 'Role', 'table_name' => 'roles']);
        $role->fields()->create(['name' => 'name', 'label' => 'Name', 'type' => 'string']);
        $model->relationships()->create([
            'target_model_id' => $role->id,
            'name' => 'roles',
            'type' => 'belongsToMany',
        ]);
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
        $page->controls()->create(['control_type' => 'table', 'label' => 'Customer records']);
        $createPage = $iteration->pages()->create(['model_definition_id' => $model->id, 'name' => 'Create customer', 'slug' => 'customers/create', 'page_type' => 'create']);
        $createPage->controls()->create([
            'model_field_id' => $field->id,
            'control_type' => 'select',
            'label' => 'Email',
            'width' => 'half',
            'configuration' => ['options' => [['value' => 'primary', 'label' => 'Primary']]],
        ]);
        $createPage->controls()->create(['control_type' => 'button', 'label' => 'Save']);
        $editPage = $iteration->pages()->create(['model_definition_id' => $model->id, 'name' => 'Edit customer', 'slug' => 'customers/edit', 'page_type' => 'edit']);
        $editPage->controls()->create(['model_field_id' => $field->id, 'control_type' => 'input', 'label' => 'Email']);
        $editPage->controls()->create(['control_type' => 'button', 'label' => 'Save']);
        $showPage = $iteration->pages()->create(['model_definition_id' => $model->id, 'name' => 'Customer', 'slug' => 'customers/show', 'page_type' => 'show']);
        $showPage->controls()->create(['model_field_id' => $field->id, 'control_type' => 'input', 'label' => 'Email']);

        app(IterationValidator::class)->run($iteration);
        $result = app(LaravelArtifactGenerator::class)->generate($iteration->fresh());

        $this->assertContains('app/Models/Customer.php', $result['files']);
        Storage::disk('local')->assertExists('generated/crm/iteration-1/app/Models/Customer.php');
        Storage::disk('local')->assertExists('generated/crm/iteration-1/resources/views/pages/customers.blade.php');
        Storage::disk('local')->assertExists('generated/crm/iteration-1/routes/generated.php');
        Storage::disk('local')->assertExists('generated/crm/iteration-1/visual-builder.json');
        Storage::disk('local')->assertExists('generated/crm/iteration-1/.github/workflows/tests.yml');
        Storage::disk('local')->assertExists('generated/crm/iteration-1/Dockerfile');
        Storage::disk('local')->assertExists('generated/crm/iteration-1/compose.yaml');
        Storage::disk('local')->assertExists('generated/crm/iteration-1/.github/workflows/publish-image.yml');
        Storage::disk('local')->assertExists('generated/crm/iteration-1/app/Http/Controllers/Api/CustomerController.php');
        Storage::disk('local')->assertExists('generated/crm/iteration-1/routes/generated-api.php');
        Storage::disk('local')->assertExists('generated/crm/iteration-1/tests/Feature/GeneratedSchemaTest.php');
        $this->assertStringContainsString('pdo_pgsql', Storage::disk('local')->get('generated/crm/iteration-1/Dockerfile'));
        $this->assertStringContainsString('DB_CONNECTION: pgsql', Storage::disk('local')->get('generated/crm/iteration-1/compose.yaml'));
        $modelSource = Storage::disk('local')->get('generated/crm/iteration-1/app/Models/Customer.php');
        $migrationPath = collect(Storage::disk('local')->files('generated/crm/iteration-1/database/migrations'))
            ->first(fn (string $path): bool => str_contains($path, 'create_customers_table'));
        $this->assertNotNull($migrationPath);
        $migrationSource = Storage::disk('local')->get($migrationPath);
        $pivotPath = collect(Storage::disk('local')->files('generated/crm/iteration-1/database/migrations'))
            ->first(fn (string $path): bool => str_contains($path, 'create_customer_role_table'));
        $this->assertNotNull($pivotPath);
        $pivotSource = Storage::disk('local')->get($pivotPath);
        $pageSource = Storage::disk('local')->get('generated/crm/iteration-1/resources/views/pages/customers.blade.php');
        $this->assertStringContainsString('public function parent(): BelongsTo', $modelSource);
        $this->assertStringContainsString("foreignId('parent_id')->constrained('customers')", $migrationSource);
        $this->assertStringContainsString("string('email')->default('unknown@example.com')->index()->unique()", $migrationSource);
        $this->assertStringContainsString("foreignId('customer_id')->constrained('customers')", $pivotSource);
        $this->assertStringContainsString("foreignId('role_id')->constrained('roles')", $pivotSource);
        $this->assertStringContainsString("return ['records' => Customer::query()->latest()->get()]", $pageSource);
        $this->assertStringContainsString('@forelse ($records as $record)', $pageSource);
        $createSource = Storage::disk('local')->get('generated/crm/iteration-1/resources/views/pages/customers/create.blade.php');
        $editSource = Storage::disk('local')->get('generated/crm/iteration-1/resources/views/pages/customers/edit.blade.php');
        $showSource = Storage::disk('local')->get('generated/crm/iteration-1/resources/views/pages/customers/show.blade.php');
        $routesSource = Storage::disk('local')->get('generated/crm/iteration-1/routes/generated.php');
        $apiRoutesSource = Storage::disk('local')->get('generated/crm/iteration-1/routes/generated-api.php');
        $schemaTestSource = Storage::disk('local')->get('generated/crm/iteration-1/tests/Feature/GeneratedSchemaTest.php');
        $this->assertStringContainsString('Customer::query()->create($validated)', $createSource);
        $this->assertStringContainsString('<flux:select.option value="primary">Primary</flux:select.option>', $createSource);
        $this->assertStringContainsString('<div class="max-w-2xl">', $createSource);
        $this->assertStringContainsString('findOrFail($this->recordId)->update($validated)', $editSource);
        $this->assertStringNotContainsString('function save', $showSource);
        $this->assertStringContainsString("'/customers/edit/{record}'", $routesSource);
        $this->assertStringContainsString("Route::apiResource('customers', CustomerController::class)", $apiRoutesSource);
        $this->assertStringContainsString("Schema::hasColumns('customers', ['id', 'email'])", $schemaTestSource);
        $this->assertStringContainsString("Schema::hasColumns('roles', ['id', 'name'])", $schemaTestSource);
        foreach ([
            'generated/crm/iteration-1/app/Models/Customer.php',
            $migrationPath,
            'generated/crm/iteration-1/resources/views/pages/customers/create.blade.php',
            'generated/crm/iteration-1/resources/views/pages/customers/edit.blade.php',
            'generated/crm/iteration-1/resources/views/pages/customers/show.blade.php',
            'generated/crm/iteration-1/app/Http/Controllers/Api/CustomerController.php',
            'generated/crm/iteration-1/routes/generated-api.php',
            'generated/crm/iteration-1/tests/Feature/GeneratedSchemaTest.php',
        ] as $generatedPhp) {
            $lint = new Process([PHP_BINARY, '-l', Storage::disk('local')->path($generatedPhp)]);
            $lint->run();
            $this->assertTrue($lint->isSuccessful(), $lint->getErrorOutput());
        }
        $this->assertSame('generated', $iteration->fresh()->status);

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
            ->call('editModel', $contact->id)
            ->set('modelName', 'ContactRecord')
            ->set('modelTableName', 'contact_records')
            ->call('saveModel')
            ->set('fieldName', 'email')
            ->set('fieldLabel', 'Email address')
            ->set('fieldType', 'string')
            ->set('fieldRules', 'required|email|max:255')
            ->set('fieldUnique', true)
            ->call('addField');

        $this->assertSame('ContactRecord', $contact->fresh()->name);
        $this->assertSame('contact_records', $contact->fresh()->table_name);

        $field = $contact->fields()->firstOrFail();
        $this->assertSame(['required', 'email', 'max:255'], $field->validation_rules);
        $this->assertTrue($field->unique);
        $component
            ->call('editField', $field->id)
            ->set('fieldLabel', 'Primary email')
            ->set('fieldRules', 'required|email')
            ->set('fieldDefault', 'none@example.com')
            ->set('fieldIndexed', true)
            ->call('saveField');
        $this->assertSame('Primary email', $field->fresh()->label);
        $this->assertSame(['required', 'email'], $field->fresh()->validation_rules);
        $this->assertSame('none@example.com', $field->fresh()->default_value);
        $this->assertTrue($field->fresh()->indexed);
        $component
            ->call('editField', $field->id)
            ->set('fieldType', 'integer')
            ->set('fieldDefault', 'not-a-number')
            ->call('saveField')
            ->assertHasErrors('fieldDefault');
        $component->call('cancelFieldEdit');

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
        $control = $page->controls()->firstOrFail();
        $component
            ->call('editPage', $page->id)
            ->set('pageName', 'Contact directory')
            ->set('pageSlug', 'contact-directory')
            ->call('savePage')
            ->call('editControl', $control->id)
            ->set('controlType', 'select')
            ->set('controlLabel', 'Primary email field')
            ->set('controlWidth', 'half')
            ->set('controlOptions', "primary:Primary\nsecondary:Secondary")
            ->call('saveControl');
        $this->assertSame('contact-directory', $page->fresh()->slug);
        $this->assertSame('select', $control->fresh()->control_type);
        $this->assertSame('Primary email field', $control->fresh()->label);
        $this->assertSame('half', $control->fresh()->width);
        $this->assertSame('Secondary', $control->fresh()->configuration['options'][1]['label']);

        $component
            ->set('pluginType', 'npm')
            ->set('pluginPackage', 'sortablejs')
            ->set('pluginConstraint', '^1.15')
            ->call('addPlugin');
        $plugin = $iteration->plugins()->firstOrFail();
        $this->assertFalse($plugin->approved);
        $component->call('togglePluginApproval', $plugin->id);
        $this->assertTrue($plugin->fresh()->approved);
        $component->call('removePlugin', $plugin->id);

        $this->assertDatabaseHas('model_relationships', ['source_model_id' => $contact->id, 'target_model_id' => $customer->id]);
        $this->assertDatabaseHas('control_definitions', ['page_definition_id' => $page->id, 'model_field_id' => $field->id]);
        $this->assertDatabaseMissing('plugin_requirements', ['id' => $plugin->id]);
        $this->assertSame('draft', $iteration->fresh()->status);
    }

    public function test_docker_generation_honours_the_selected_database_driver(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $expectations = [
            'pgsql' => ['pdo_pgsql', 'DB_CONNECTION: pgsql'],
            'mysql' => ['pdo_mysql', 'DB_CONNECTION: mysql'],
            'sqlite' => ['pdo_sqlite', 'DB_CONNECTION: sqlite'],
        ];

        foreach ($expectations as $driver => [$extension, $connection]) {
            $project = $user->builderProjects()->create([
                'name' => strtoupper($driver),
                'slug' => 'docker-'.$driver,
                'database_driver' => $driver,
                'docker_enabled' => true,
            ]);
            $iteration = $project->iterations()->create(['number' => 1, 'name' => 'Initial build']);
            $model = $iteration->models()->create(['name' => 'Record', 'table_name' => 'records']);
            $field = $model->fields()->create(['name' => 'name', 'label' => 'Name', 'type' => 'string']);
            $page = $iteration->pages()->create(['model_definition_id' => $model->id, 'name' => 'Records', 'slug' => 'records', 'page_type' => 'index']);
            $page->controls()->create(['model_field_id' => $field->id, 'control_type' => 'input', 'label' => 'Name']);
            app(IterationValidator::class)->run($iteration);
            app(LaravelArtifactGenerator::class)->generate($iteration->fresh());
            $root = "generated/docker-{$driver}/iteration-1";

            $this->assertStringContainsString($extension, Storage::disk('local')->get("{$root}/Dockerfile"));
            $this->assertStringContainsString($connection, Storage::disk('local')->get("{$root}/compose.yaml"));
        }
    }

    public function test_editor_reorders_and_deletes_schema_and_page_items(): void
    {
        $user = User::factory()->create();
        $project = $user->builderProjects()->create(['name' => 'Editor', 'slug' => 'editor']);
        $iteration = $project->iterations()->create(['number' => 1, 'name' => 'Initial build', 'status' => 'generated']);
        $model = $iteration->models()->create(['name' => 'Customer', 'table_name' => 'customers']);
        $firstField = $model->fields()->create(['name' => 'name', 'label' => 'Name', 'type' => 'string', 'position' => 0]);
        $secondField = $model->fields()->create(['name' => 'email', 'label' => 'Email', 'type' => 'string', 'position' => 1]);
        $firstPage = $iteration->pages()->create(['model_definition_id' => $model->id, 'name' => 'Customers', 'slug' => 'customers', 'position' => 0]);
        $secondPage = $iteration->pages()->create(['model_definition_id' => $model->id, 'name' => 'Create customer', 'slug' => 'customers/create', 'position' => 1]);
        $firstControl = $firstPage->controls()->create(['model_field_id' => $firstField->id, 'control_type' => 'input', 'label' => 'Name', 'position' => 0]);
        $secondControl = $firstPage->controls()->create(['model_field_id' => $secondField->id, 'control_type' => 'input', 'label' => 'Email', 'position' => 1]);

        $component = Livewire::actingAs($user)->test('pages::projects.show', ['project' => $project])
            ->set('selectedModelId', $model->id)
            ->set('selectedPageId', $firstPage->id);
        $component->call('moveField', $secondField->id, 'up');
        $component->call('moveControl', $secondControl->id, 'up');
        $component->call('movePage', $secondPage->id, 'up');

        $this->assertSame(0, $secondField->fresh()->position);
        $this->assertSame(0, $secondControl->fresh()->position);
        $this->assertSame(0, $secondPage->fresh()->position);
        $this->assertSame('draft', $iteration->fresh()->status);

        $component->call('deleteControl', $firstControl->id);
        $component->call('deleteField', $firstField->id);
        $component->call('deletePage', $secondPage->id);
        $this->assertModelMissing($firstControl);
        $this->assertModelMissing($firstField);
        $this->assertModelMissing($secondPage);

        $component->call('deleteModel', $model->id);
        $this->assertModelMissing($model);
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
            'template' => 'application-api',
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
        $iteration->plugins()->create(['package' => 'sortablejs', 'constraint' => '^1.15', 'type' => 'npm', 'approved' => true]);

        app(IterationValidator::class)->run($iteration);
        app(LaravelArtifactGenerator::class)->generate($iteration);
        $run = app(LaravelProjectAssembler::class)->assemble($iteration->fresh());

        $this->assertSame('passed', $run->status);
        $this->assertSame('assembled', $project->fresh()->status);
        $this->assertFileExists($outputPath.DIRECTORY_SEPARATOR.'visual-builder.json');
        $this->assertStringContainsString("require __DIR__.'/generated.php';", file_get_contents($outputPath.DIRECTORY_SEPARATOR.'routes'.DIRECTORY_SEPARATOR.'web.php'));
        $this->assertContains(['composer', 'require', 'spatie/laravel-permission:^7.0'], $runner->commands);
        $this->assertContains(['npm', 'install', 'sortablejs@^1.15'], $runner->commands);
        $this->assertContains(['npm', 'run', 'build'], $runner->commands);
        $this->assertContains([PHP_BINARY, 'artisan', 'install:api', '--no-interaction'], $runner->commands);
        $this->assertStringContainsString("require __DIR__.'/generated-api.php';", file_get_contents($outputPath.DIRECTORY_SEPARATOR.'routes'.DIRECTORY_SEPARATOR.'api.php'));

        file_put_contents($outputPath.DIRECTORY_SEPARATOR.'.env', 'SECRET=do-not-package');
        mkdir($outputPath.DIRECTORY_SEPARATOR.'node_modules', recursive: true);
        file_put_contents($outputPath.DIRECTORY_SEPARATOR.'node_modules'.DIRECTORY_SEPARATOR.'temporary.js', 'ignored');
        $applicationPackage = app(IterationPackager::class)->zipApplication($iteration->fresh());
        $this->assertSame('application-zip', $applicationPackage->format);
        $archive = new \ZipArchive;
        $this->assertTrue($archive->open($applicationPackage->path));
        $this->assertFalse($archive->locateName('.env'));
        $this->assertFalse($archive->locateName('node_modules/temporary.js'));
        $this->assertNotFalse($archive->locateName('artisan'));
        $archive->close();

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

    public function test_github_publisher_commits_and_pushes_an_assembled_application(): void
    {
        $runner = new FakeProcessRunner;
        $this->app->instance(ProcessRunner::class, $runner);
        $user = User::factory()->create();
        $outputPath = storage_path('framework/testing/github-app');
        if (! is_dir($outputPath)) {
            mkdir($outputPath, recursive: true);
        }
        touch($outputPath.DIRECTORY_SEPARATOR.'artisan');
        $project = $user->builderProjects()->create([
            'name' => 'GitHub App',
            'slug' => 'github-app',
            'output_path' => $outputPath,
            'status' => 'assembled',
        ]);
        $iteration = $project->iterations()->create(['number' => 3, 'name' => 'Release']);

        $run = app(GitHubPublisher::class)->publish($iteration, 'cjrowell1980/generated-app');

        $this->assertSame('passed', $run->status);
        $this->assertSame('published', $project->fresh()->status);
        $this->assertSame('cjrowell1980/generated-app', $project->fresh()->github_repository);
        $this->assertContains(['git', 'commit', '-m', 'Build iteration 3'], $runner->commands);
        $this->assertContains(['git', 'push', '--set-upstream', 'origin', 'HEAD'], $runner->commands);
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
            file_put_contents($outputPath.DIRECTORY_SEPARATOR.'artisan', "<?php\n");
        }
        if (in_array('install:api', $command, true)) {
            file_put_contents($workingDirectory.DIRECTORY_SEPARATOR.'routes'.DIRECTORY_SEPARATOR.'api.php', "<?php\n");
        }

        $output = $command === ['git', 'remote', 'get-url', 'origin']
            ? 'https://github.com/cjrowell1980/generated-app.git'
            : implode(' ', $command);

        return ['successful' => true, 'output' => $output, 'exit_code' => 0];
    }
}
