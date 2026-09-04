<?php

namespace Tests\Feature;

use App\Models\BuilderProject;
use App\Models\User;
use App\Services\Generation\LaravelArtifactGenerator;
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
        $model->fields()->create(['name' => 'email', 'type' => 'string', 'indexed' => true]);

        $result = app(LaravelArtifactGenerator::class)->generate($iteration);

        $this->assertContains('app/Models/Customer.php', $result['files']);
        Storage::disk('local')->assertExists('generated/crm/iteration-1/app/Models/Customer.php');
        Storage::disk('local')->assertExists('generated/crm/iteration-1/visual-builder.json');
        $this->assertSame('generated', $iteration->fresh()->status);
    }
}
