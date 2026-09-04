<?php

use App\Models\BuilderProject;
use Illuminate\Support\Str;
use Livewire\Component;

new class extends Component {
    public string $name = '';
    public string $description = '';

    public function createProject(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $base = Str::slug($data['name']) ?: 'project';
        $slug = $base;
        $suffix = 2;
        while (auth()->user()->builderProjects()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        $project = auth()->user()->builderProjects()->create([...$data, 'slug' => $slug]);
        $project->iterations()->create(['number' => 1, 'name' => 'Initial build']);

        $this->redirectRoute('projects.show', $project, navigate: true);
    }

    public function with(): array
    {
        return ['projects' => auth()->user()->builderProjects()->withCount('iterations')->latest()->get()];
    }
};
?>

<div class="mx-auto w-full max-w-6xl space-y-8 p-6 lg:p-10">
    <div>
        <flux:heading size="xl">Visual Builder</flux:heading>
        <flux:text class="mt-2">Design Laravel applications as versioned schemas, then generate reviewable code.</flux:text>
    </div>

    <div class="grid gap-6 lg:grid-cols-[22rem_1fr]">
        <form wire:submit="createProject" class="space-y-5 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading>Create a project</flux:heading>
            <flux:input wire:model="name" label="Project name" placeholder="Operations portal" />
            <flux:textarea wire:model="description" label="What should it do?" rows="4" />
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
    </div>
</div>
