<?php

use App\Models\BuilderProject;
use App\Services\System\DevelopmentEnvironment;
use Illuminate\Support\Str;
use Livewire\Component;

new class extends Component {
    public string $name = '';
    public string $description = '';
    public string $template = 'application';
    public string $databaseDriver = 'pgsql';
    public bool $dockerEnabled = false;
    public string $outputPath = '';
    public array $capabilities = [];

    public function mount(DevelopmentEnvironment $environment): void
    {
        $this->capabilities = $environment->capabilities();
    }

    public function createProject(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'template' => ['required', 'in:application,api,application-api'],
            'databaseDriver' => ['required', 'in:pgsql,mysql,sqlite'],
            'dockerEnabled' => ['boolean'],
            'outputPath' => ['nullable', 'string', 'max:500'],
        ]);

        $base = Str::slug($data['name']) ?: 'project';
        $slug = $base;
        $suffix = 2;
        while (auth()->user()->builderProjects()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        $project = auth()->user()->builderProjects()->create([
            'name' => $data['name'],
            'description' => $data['description'],
            'slug' => $slug,
            'template' => $data['template'],
            'database_driver' => $data['databaseDriver'],
            'docker_enabled' => $data['dockerEnabled'],
            'output_path' => $data['outputPath'] ?: null,
        ]);
        $project->iterations()->create(['number' => 1, 'name' => 'Initial build']);

        $this->redirectRoute('projects.show', $project, navigate: true);
    }

    public function with(): array
    {
        return ['projects' => auth()->user()->builderProjects()->withCount('iterations')->latest()->get()];
    }
};
?>

<div class="mx-auto w-full max-w-7xl space-y-8 p-6 lg:p-10">
    <div>
        <flux:heading size="xl">Visual Builder</flux:heading>
        <flux:text class="mt-2">Design Laravel applications as versioned schemas, then generate reviewable code.</flux:text>
    </div>

    <div class="grid gap-6 xl:grid-cols-[24rem_1fr_18rem]">
        <form wire:submit="createProject" class="space-y-5 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading>Create a project</flux:heading>
            <flux:input wire:model="name" label="Project name" placeholder="Operations portal" />
            <flux:textarea wire:model="description" label="What should it do?" rows="4" />
            <flux:select wire:model="template" label="Project type">
                <flux:select.option value="application">Web application</flux:select.option>
                <flux:select.option value="api">API only</flux:select.option>
                <flux:select.option value="application-api">Web application and API</flux:select.option>
            </flux:select>
            <flux:select wire:model="databaseDriver" label="Database">
                <flux:select.option value="pgsql">PostgreSQL</flux:select.option>
                <flux:select.option value="mysql">MySQL</flux:select.option>
                <flux:select.option value="sqlite">SQLite</flux:select.option>
            </flux:select>
            <flux:checkbox wire:model="dockerEnabled" label="Include Docker environment" />
            <flux:input wire:model="outputPath" label="Project folder (optional)" placeholder="C:\Projects\my-app" />
            <flux:button type="submit" variant="primary" class="w-full">Start building</flux:button>
        </form>

        <div class="space-y-3">
            @forelse ($projects as $project)
                <a href="{{ route('projects.show', $project) }}" wire:navigate class="block rounded-xl border border-zinc-200 bg-white p-5 transition hover:border-indigo-400 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <flux:heading>{{ $project->name }}</flux:heading>
                            <flux:text class="mt-1">{{ $project->description ?: 'No description yet.' }}</flux:text>
                        </div>
                        <flux:badge>{{ $project->iterations_count }} iteration{{ $project->iterations_count === 1 ? '' : 's' }}</flux:badge>
                    </div>
                </a>
            @empty
                <div class="rounded-xl border border-dashed border-zinc-300 p-12 text-center dark:border-zinc-700">
                    <flux:heading>No projects yet</flux:heading>
                    <flux:text class="mt-2">Your first app starts with a name and a short brief.</flux:text>
                </div>
            @endforelse
        </div>

        <aside class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="sm">Development environment</flux:heading>
            <flux:text class="mt-1">Tools detected on this computer.</flux:text>
            <div class="mt-5 space-y-3">
                @foreach ($capabilities as $capability)
                    <div class="flex items-center justify-between gap-3 text-sm">
                        <span class="truncate" title="{{ $capability['version'] }}">{{ $capability['label'] }}</span>
                        <flux:badge :color="$capability['available'] ? 'green' : 'red'">
                            {{ $capability['available'] ? 'Ready' : 'Missing' }}
                        </flux:badge>
                    </div>
                @endforeach
            </div>
        </aside>
    </div>
</div>
