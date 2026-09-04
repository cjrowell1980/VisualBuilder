<?php

use App\Models\BuilderProject;
use App\Services\Generation\LaravelArtifactGenerator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public BuilderProject $project;

    public string $modelName = '';
    public string $fieldName = '';
    public string $fieldType = 'string';
    public bool $fieldNullable = false;
    public bool $fieldIndexed = false;
    public ?int $selectedModelId = null;
    public ?string $generatedPath = null;

    public function mount(BuilderProject $project): void
    {
        abort_unless($project->user_id === auth()->id(), 403);
        $this->project = $project;
    }

    public function addModel(): void
    {
        $this->validate(['modelName' => ['required', 'regex:/^[A-Z][A-Za-z0-9]*$/', 'max:80']]);
        $iteration = $this->iteration();
        $model = $iteration->models()->create([
            'name' => $this->modelName,
            'table_name' => Str::snake(Str::pluralStudly($this->modelName)),
        ]);
        $this->selectedModelId = $model->id;
        $this->modelName = '';
    }

    public function addField(): void
    {
        $model = $this->iteration()->models()->findOrFail($this->selectedModelId);
        $data = $this->validate([
            'fieldName' => ['required', 'regex:/^[a-z][a-z0-9_]*$/', 'max:64', Rule::unique('model_fields', 'name')->where('model_definition_id', $model->id)],
            'fieldType' => ['required', Rule::in(['string', 'text', 'integer', 'boolean', 'date', 'datetime', 'decimal', 'json'])],
            'fieldNullable' => ['boolean'],
            'fieldIndexed' => ['boolean'],
        ]);
        $model->fields()->create([
            'name' => $data['fieldName'], 'type' => $data['fieldType'],
            'nullable' => $data['fieldNullable'], 'indexed' => $data['fieldIndexed'],
            'position' => $model->fields()->count(),
        ]);
        $this->reset('fieldName', 'fieldNullable', 'fieldIndexed');
    }

    public function generate(LaravelArtifactGenerator $generator): void
    {
        $result = $generator->generate($this->iteration());
        $this->generatedPath = $result['path'];
        $this->dispatch('generated');
    }

    public function selectModel(int $modelId): void
    {
        $this->iteration()->models()->findOrFail($modelId);
        $this->selectedModelId = $modelId;
    }

    public function with(): array
    {
        $iteration = $this->iteration()->load('models.fields', 'plugins');
        if ($this->selectedModelId === null && $iteration->models->isNotEmpty()) {
            $this->selectedModelId = $iteration->models->first()->id;
        }

        return ['iteration' => $iteration];
    }

    private function iteration()
    {
        return $this->project->iterations()->latest('number')->firstOrFail();
    }
};
?>

<div class="mx-auto w-full max-w-7xl space-y-6 p-6 lg:p-10">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="{{ route('projects.index') }}" wire:navigate>Projects</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ $project->name }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <flux:heading size="xl" class="mt-3">{{ $project->name }}</flux:heading>
            <flux:text class="mt-1">Iteration {{ $iteration->number }} · {{ $iteration->name }}</flux:text>
        </div>
        <flux:button wire:click="generate" variant="primary" icon="code-bracket">Generate code</flux:button>
    </div>

    @if ($generatedPath)
        <flux:callout variant="success" icon="check-circle" heading="Code generated">
            <flux:callout.text>The artifact bundle is ready at {{ $generatedPath }}</flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid min-h-[38rem] overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 lg:grid-cols-[17rem_1fr_20rem]">
        <aside class="border-b border-zinc-200 p-5 dark:border-zinc-700 lg:border-b-0 lg:border-r">
            <flux:heading size="sm">Models</flux:heading>
            <div class="mt-4 space-y-1">
                @foreach ($iteration->models as $model)
                    <button type="button" wire:click="selectModel({{ $model->id }})" class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm {{ $selectedModelId === $model->id ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-200' : 'hover:bg-zinc-100 dark:hover:bg-zinc-800' }}">
                        <span>{{ $model->name }}</span><span class="text-xs opacity-60">{{ $model->fields->count() }}</span>
                    </button>
                @endforeach
            </div>
            <form wire:submit="addModel" class="mt-5 space-y-3">
                <flux:input wire:model="modelName" label="New model" placeholder="Customer" />
                <flux:button type="submit" size="sm" class="w-full">Add model</flux:button>
            </form>
        </aside>

        <main class="p-5 lg:p-7">
            @php($selected = $iteration->models->firstWhere('id', $selectedModelId))
            @if ($selected)
                <div class="flex items-center justify-between">
                    <div><flux:heading>{{ $selected->name }}</flux:heading><flux:text>{{ $selected->table_name }}</flux:text></div>
                    <flux:badge>{{ $selected->fields->count() }} fields</flux:badge>
                </div>
                <div class="mt-6 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                    @forelse ($selected->fields as $field)
                        <div class="grid grid-cols-[1fr_8rem_6rem] items-center gap-3 border-b border-zinc-100 px-4 py-3 text-sm last:border-0 dark:border-zinc-800">
                            <code>{{ $field->name }}</code><span>{{ $field->type }}</span><span class="text-xs text-zinc-500">{{ $field->nullable ? 'nullable' : 'required' }}{{ $field->indexed ? ' · index' : '' }}</span>
                        </div>
                    @empty
                        <div class="p-10 text-center text-sm text-zinc-500">Add the first field using the inspector.</div>
                    @endforelse
                </div>
            @else
                <div class="grid h-full place-items-center text-center"><div><flux:heading>Start with a model</flux:heading><flux:text class="mt-2">Models define the data and become migrations, validation, and UI.</flux:text></div></div>
            @endif
        </main>

        <aside class="border-t border-zinc-200 p-5 dark:border-zinc-700 lg:border-l lg:border-t-0">
            <flux:heading size="sm">Field inspector</flux:heading>
            <form wire:submit="addField" class="mt-5 space-y-4">
                <flux:input wire:model="fieldName" label="Name" placeholder="email_address" :disabled="!$selectedModelId" />
                <flux:select wire:model="fieldType" label="Type" :disabled="!$selectedModelId">
                    @foreach (['string', 'text', 'integer', 'boolean', 'date', 'datetime', 'decimal', 'json'] as $type)
                        <flux:select.option value="{{ $type }}">{{ ucfirst($type) }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:checkbox wire:model="fieldNullable" label="Allow null" />
                <flux:checkbox wire:model="fieldIndexed" label="Add database index" />
                <flux:button type="submit" class="w-full" :disabled="!$selectedModelId">Add field</flux:button>
            </form>
        </aside>
    </div>
</div>
