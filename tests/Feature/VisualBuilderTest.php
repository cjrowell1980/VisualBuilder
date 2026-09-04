<?php

namespace Tests\Feature;

use App\Models\BuilderProject;
use App\Models\User;
use App\Services\Debugging\IterationValidator;
use App\Services\Generation\LaravelArtifactGenerator;
use App\Services\Iterations\IterationCloner;
use App\Services\Packaging\IterationPackager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
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
}
