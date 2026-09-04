<?php

use App\Models\BuildIteration;
use App\Models\BuilderProject;
use App\Services\Generation\LaravelArtifactGenerator;
use App\Services\Iterations\IterationCloner;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public BuilderProject $project;

    public string $mode = 'schema';
    public string $modelName = '';
    public string $fieldName = '';
    public string $fieldLabel = '';
    public string $fieldType = 'string';
    public string $fieldRules = '';
    public bool $fieldNullable = false;
    public bool $fieldIndexed = false;
    public bool $fieldUnique = false;
    public ?int $selectedModelId = null;
    public string $relationshipName = '';
    public string $relationshipType = 'belongsTo';
    public ?int $relationshipTargetId = null;
    public string $pageName = '';
    public string $pageSlug = '';
    public string $pageType = 'custom';
    public ?int $pageModelId = null;
    public ?int $selectedPageId = null;
    public string $controlType = 'input';
    public string $controlLabel = '';
    public ?int $controlFieldId = null;
    public ?string $generatedPath = null;
    public string $iterationName = '';

    public function mount(BuilderProject $project): void
    {
        abort_unless($project->user_id === auth()->id(), 403);
        $this->project = $project;
    }

    public function setMode(string $mode): void
    {
        abort_unless(in_array($mode, ['schema', 'pages', 'preview', 'publish'], true), 404);
        $this->mode = $mode;
    }

    public function addModel(): void
    {
        $this->validate(['modelName' => ['required', 'regex:/^[A-Z][A-Za-z0-9]*$/', 'max:80']]);
        $model = $this->iteration()->models()->create([
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
            'fieldLabel' => ['nullable', 'string', 'max:100'],
            'fieldType' => ['required', Rule::in(['string', 'text', 'integer', 'boolean', 'date', 'datetime', 'decimal', 'json'])],
            'fieldRules' => ['nullable', 'string', 'max:500'],
            'fieldNullable' => ['boolean'],
            'fieldIndexed' => ['boolean'],
            'fieldUnique' => ['boolean'],
        ]);
        $model->fields()->create([
            'name' => $data['fieldName'],
            'label' => $data['fieldLabel'] ?: Str::headline($data['fieldName']),
            'type' => $data['fieldType'],
            'validation_rules' => array_values(array_filter(array_map('trim', explode('|', $data['fieldRules'])))),
            'nullable' => $data['fieldNullable'],
            'indexed' => $data['fieldIndexed'],
            'unique' => $data['fieldUnique'],
            'position' => $model->fields()->count(),
        ]);
        $this->reset('fieldName', 'fieldLabel', 'fieldRules', 'fieldNullable', 'fieldIndexed', 'fieldUnique');
    }

    public function addRelationship(): void
    {
        $source = $this->iteration()->models()->findOrFail($this->selectedModelId);
        $target = $this->iteration()->models()->findOrFail($this->relationshipTargetId);
        $data = $this->validate([
            'relationshipName' => ['required', 'regex:/^[a-z][A-Za-z0-9]*$/', 'max:80'],
            'relationshipType' => ['required', Rule::in(['belongsTo', 'hasOne', 'hasMany', 'belongsToMany'])],
        ]);
        $source->relationships()->create([
            'target_model_id' => $target->id,
            'name' => $data['relationshipName'],
            'type' => $data['relationshipType'],
            'foreign_key' => $data['relationshipType'] === 'belongsTo' ? Str::snake($data['relationshipName']).'_id' : null,
        ]);
        $this->reset('relationshipName', 'relationshipTargetId');
    }

    public function addPage(): void
    {
        $iteration = $this->iteration();
        $data = $this->validate([
            'pageName' => ['required', 'string', 'max:100'],
            'pageSlug' => ['nullable', 'regex:/^[a-z0-9]+(?:[\/-][a-z0-9]+)*$/', 'max:120'],
            'pageType' => ['required', Rule::in(['custom', 'index', 'create', 'edit', 'show', 'dashboard'])],
            'pageModelId' => ['nullable', 'integer'],
        ]);
        $modelId = $data['pageModelId'] ? $iteration->models()->findOrFail($data['pageModelId'])->id : null;
        $base = $data['pageSlug'] ?: Str::slug($data['pageName']);
        $slug = $base;
        $suffix = 2;
        while ($iteration->pages()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }
        $page = $iteration->pages()->create([
            'model_definition_id' => $modelId,
            'name' => $data['pageName'],
            'slug' => $slug,
            'page_type' => $data['pageType'],
            'position' => $iteration->pages()->count(),
        ]);
        $this->selectedPageId = $page->id;
        $this->reset('pageName', 'pageSlug', 'pageModelId');
    }

    public function addControl(): void
    {
        $page = $this->iteration()->pages()->findOrFail($this->selectedPageId);
        $fieldId = null;
        if ($this->controlFieldId) {
            $fieldId = $this->iteration()->models()->whereHas('fields', fn ($query) => $query->whereKey($this->controlFieldId))->exists()
                ? $this->controlFieldId
                : abort(404);
        }
        $data = $this->validate([
            'controlType' => ['required', Rule::in(['heading', 'text', 'input', 'textarea', 'select', 'checkbox', 'button', 'table'])],
            'controlLabel' => ['nullable', 'string', 'max:100'],
        ]);
        $page->controls()->create([
            'model_field_id' => $fieldId,
            'control_type' => $data['controlType'],
            'label' => $data['controlLabel'] ?: Str::headline($data['controlType']),
            'position' => $page->controls()->count(),
        ]);
        $this->reset('controlLabel', 'controlFieldId');
    }

    public function generate(LaravelArtifactGenerator $generator): void
    {
        $result = $generator->generate($this->iteration());
        $this->generatedPath = $result['path'];
    }

    public function createIteration(IterationCloner $cloner): void
    {
        $data = $this->validate(['iterationName' => ['required', 'string', 'max:100']]);
        $cloner->clone($this->iteration(), $data['iterationName']);
        $this->reset('iterationName', 'selectedModelId', 'selectedPageId', 'generatedPath');
    }

    public function selectModel(int $modelId): void
    {
        $this->iteration()->models()->findOrFail($modelId);
        $this->selectedModelId = $modelId;
    }

    public function selectPage(int $pageId): void
    {
        $this->iteration()->pages()->findOrFail($pageId);
        $this->selectedPageId = $pageId;
    }

    public function with(): array
    {
        $iteration = $this->iteration()->load('models.fields', 'models.relationships.target', 'pages.controls.field', 'pages.modelDefinition', 'plugins');
        $this->selectedModelId ??= $iteration->models->first()?->id;
        $this->selectedPageId ??= $iteration->pages->first()?->id;

        return ['iteration' => $iteration];
    }

    private function iteration(): BuildIteration
    {
        return $this->project->iterations()->latest('number')->firstOrFail();
    }
};
?>

<div class="mx-auto w-full max-w-[100rem] space-y-5 p-5 lg:p-8">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:breadcrumbs><flux:breadcrumbs.item href="{{ route('projects.index') }}" wire:navigate>Projects</flux:breadcrumbs.item><flux:breadcrumbs.item>{{ $project->name }}</flux:breadcrumbs.item></flux:breadcrumbs>
            <flux:heading size="xl" class="mt-2">{{ $project->name }}</flux:heading>
            <flux:text>Iteration {{ $iteration->number }} · {{ $project->template }} · {{ $project->database_driver }}{{ $project->docker_enabled ? ' · Docker' : '' }}</flux:text>
        </div>
        <div class="flex gap-2"><flux:modal.trigger name="new-iteration"><flux:button icon="document-duplicate">New iteration</flux:button></flux:modal.trigger><flux:button wire:click="generate" variant="primary" icon="code-bracket">Generate iteration</flux:button></div>
    </header>

    <nav class="flex gap-1 border-b border-zinc-200 pb-2 dark:border-zinc-700">
        @foreach (['schema' => 'Data', 'pages' => 'Pages', 'preview' => 'Preview', 'publish' => 'Publish'] as $key => $label)
            <flux:button wire:click="setMode('{{ $key }}')" size="sm" :variant="$mode === $key ? 'primary' : 'ghost'">{{ $label }}</flux:button>
        @endforeach
    </nav>

    @if ($generatedPath)<flux:callout variant="success" icon="check-circle" heading="Iteration generated"><flux:callout.text>{{ $generatedPath }}</flux:callout.text></flux:callout>@endif

    <flux:modal name="new-iteration" class="md:w-96"><form wire:submit="createIteration" class="space-y-5"><div><flux:heading size="lg">Create iteration</flux:heading><flux:text class="mt-1">Clone the current design into a new editable version.</flux:text></div><flux:input wire:model="iterationName" label="Iteration name" placeholder="Add customer approvals" /><div class="flex justify-end gap-2"><flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close><flux:button type="submit" variant="primary">Create iteration</flux:button></div></form></flux:modal>

    @if ($mode === 'schema')
        <div class="grid min-h-[40rem] overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 lg:grid-cols-[17rem_1fr_21rem]">
            <aside class="border-r border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading size="sm">Models</flux:heading>
                <div class="mt-4 space-y-1">@foreach ($iteration->models as $model)<button type="button" wire:click="selectModel({{ $model->id }})" class="flex w-full justify-between rounded-lg px-3 py-2 text-left text-sm {{ $selectedModelId === $model->id ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-200' : 'hover:bg-zinc-100 dark:hover:bg-zinc-800' }}"><span>{{ $model->name }}</span><span>{{ $model->fields->count() }}</span></button>@endforeach</div>
                <form wire:submit="addModel" class="mt-5 space-y-3"><flux:input wire:model="modelName" label="New model" placeholder="Customer" /><flux:button type="submit" size="sm" class="w-full">Add model</flux:button></form>
            </aside>
            <main class="p-6">
                @php($selected = $iteration->models->firstWhere('id', $selectedModelId))
                @if ($selected)
                    <div class="flex justify-between"><div><flux:heading>{{ $selected->name }}</flux:heading><flux:text>{{ $selected->table_name }}</flux:text></div><flux:badge>{{ $selected->fields->count() }} fields</flux:badge></div>
                    <div class="mt-6 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">@forelse ($selected->fields as $field)<div class="grid grid-cols-[1fr_7rem_8rem] gap-3 border-b border-zinc-100 px-4 py-3 text-sm last:border-0 dark:border-zinc-800"><div><div>{{ $field->label }}</div><code class="text-xs">{{ $field->name }}</code></div><span>{{ $field->type }}</span><span class="text-xs text-zinc-500">{{ $field->nullable ? 'nullable' : 'required' }}{{ $field->unique ? ' · unique' : '' }}</span></div>@empty<div class="p-10 text-center text-sm text-zinc-500">Add the first field using the inspector.</div>@endforelse</div>
                    <div class="mt-7"><flux:heading size="sm">Relationships</flux:heading><div class="mt-3 space-y-2">@forelse($selected->relationships as $relationship)<div class="flex justify-between rounded-lg bg-zinc-50 px-3 py-2 text-sm dark:bg-zinc-800"><code>{{ $relationship->name }}()</code><span>{{ $relationship->type }} {{ $relationship->target->name }}</span></div>@empty<flux:text>No relationships defined.</flux:text>@endforelse</div></div>
                @else<div class="grid h-full place-items-center"><flux:heading>Start with a model</flux:heading></div>@endif
            </main>
            <aside class="border-l border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading size="sm">Field inspector</flux:heading>
                <form wire:submit="addField" class="mt-4 space-y-3"><flux:input wire:model="fieldName" label="Name" placeholder="email_address" /><flux:input wire:model="fieldLabel" label="Label" placeholder="Email address" /><flux:select wire:model="fieldType" label="Type">@foreach (['string','text','integer','boolean','date','datetime','decimal','json'] as $type)<flux:select.option value="{{ $type }}">{{ ucfirst($type) }}</flux:select.option>@endforeach</flux:select><flux:input wire:model="fieldRules" label="Validation rules" placeholder="required|email|max:255" /><div class="flex flex-wrap gap-4"><flux:checkbox wire:model="fieldNullable" label="Nullable" /><flux:checkbox wire:model="fieldIndexed" label="Index" /><flux:checkbox wire:model="fieldUnique" label="Unique" /></div><flux:button type="submit" class="w-full" :disabled="!$selectedModelId">Add field</flux:button></form>
                <flux:separator class="my-6" />
                <flux:heading size="sm">Relationship</flux:heading>
                <form wire:submit="addRelationship" class="mt-4 space-y-3"><flux:input wire:model="relationshipName" label="Method name" placeholder="customer" /><flux:select wire:model="relationshipType" label="Type">@foreach(['belongsTo','hasOne','hasMany','belongsToMany'] as $type)<flux:select.option value="{{ $type }}">{{ $type }}</flux:select.option>@endforeach</flux:select><flux:select wire:model="relationshipTargetId" label="Target model"><flux:select.option value="">Select model</flux:select.option>@foreach($iteration->models as $model)<flux:select.option value="{{ $model->id }}">{{ $model->name }}</flux:select.option>@endforeach</flux:select><flux:button type="submit" class="w-full" :disabled="!$selectedModelId">Add relationship</flux:button></form>
            </aside>
        </div>
    @elseif ($mode === 'pages')
        <div class="grid min-h-[40rem] overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 lg:grid-cols-[18rem_1fr_21rem]">
            <aside class="border-r border-zinc-200 p-5 dark:border-zinc-700"><flux:heading size="sm">Pages</flux:heading><div class="mt-4 space-y-1">@foreach($iteration->pages as $page)<button type="button" wire:click="selectPage({{ $page->id }})" class="flex w-full justify-between rounded-lg px-3 py-2 text-left text-sm {{ $selectedPageId === $page->id ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-200' : 'hover:bg-zinc-100 dark:hover:bg-zinc-800' }}"><span>{{ $page->name }}</span><span>{{ $page->controls->count() }}</span></button>@endforeach</div><form wire:submit="addPage" class="mt-5 space-y-3"><flux:input wire:model="pageName" label="Page name" placeholder="Customers" /><flux:input wire:model="pageSlug" label="URL" placeholder="customers" /><flux:select wire:model="pageType" label="Type">@foreach(['custom','dashboard','index','create','edit','show'] as $type)<flux:select.option value="{{ $type }}">{{ ucfirst($type) }}</flux:select.option>@endforeach</flux:select><flux:select wire:model="pageModelId" label="Model (optional)"><flux:select.option value="">None</flux:select.option>@foreach($iteration->models as $model)<flux:select.option value="{{ $model->id }}">{{ $model->name }}</flux:select.option>@endforeach</flux:select><flux:button type="submit" class="w-full">Add page</flux:button></form></aside>
            <main class="bg-zinc-50 p-6 dark:bg-zinc-950">@php($selectedPage = $iteration->pages->firstWhere('id', $selectedPageId))@if($selectedPage)<div class="min-h-full rounded-xl border border-zinc-200 bg-white p-7 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"><div class="mb-6 flex justify-between"><div><flux:heading size="xl">{{ $selectedPage->name }}</flux:heading><flux:text>/{{ $selectedPage->slug }} · {{ $selectedPage->page_type }}</flux:text></div><flux:badge>{{ $selectedPage->modelDefinition?->name ?? 'No model' }}</flux:badge></div><div class="space-y-4">@forelse($selectedPage->controls as $control)<div class="rounded-lg border border-dashed border-zinc-300 p-4 dark:border-zinc-700"><div class="flex justify-between"><span>{{ $control->label }}</span><code class="text-xs">{{ $control->control_type }}</code></div>@if($control->field)<flux:text class="mt-1">Bound to {{ $control->field->name }}</flux:text>@endif</div>@empty<div class="grid min-h-64 place-items-center text-center"><div><flux:heading>Empty canvas</flux:heading><flux:text>Add controls from the inspector.</flux:text></div></div>@endforelse</div></div>@else<div class="grid h-full place-items-center"><flux:heading>Create or select a page</flux:heading></div>@endif</main>
            <aside class="border-l border-zinc-200 p-5 dark:border-zinc-700"><flux:heading size="sm">Control inspector</flux:heading><form wire:submit="addControl" class="mt-4 space-y-3"><flux:select wire:model="controlType" label="Control">@foreach(['heading','text','input','textarea','select','checkbox','button','table'] as $type)<flux:select.option value="{{ $type }}">{{ ucfirst($type) }}</flux:select.option>@endforeach</flux:select><flux:input wire:model="controlLabel" label="Label" placeholder="Customer name" /><flux:select wire:model="controlFieldId" label="Bound field"><flux:select.option value="">None</flux:select.option>@foreach($iteration->models as $model)@foreach($model->fields as $field)<flux:select.option value="{{ $field->id }}">{{ $model->name }} · {{ $field->name }}</flux:select.option>@endforeach @endforeach</flux:select><flux:button type="submit" class="w-full" :disabled="!$selectedPageId">Add to canvas</flux:button></form></aside>
        </div>
    @elseif ($mode === 'preview')
        <div class="rounded-xl border border-zinc-200 bg-white p-10 dark:border-zinc-700 dark:bg-zinc-900"><flux:heading size="lg">Iteration preview</flux:heading><flux:text class="mt-2">{{ $iteration->models->count() }} models, {{ $iteration->pages->count() }} pages and {{ $iteration->pages->sum(fn($page) => $page->controls->count()) }} controls are ready for generation.</flux:text><flux:button wire:click="generate" variant="primary" class="mt-6">Generate review bundle</flux:button></div>
    @else
        <div class="rounded-xl border border-zinc-200 bg-white p-10 dark:border-zinc-700 dark:bg-zinc-900"><flux:heading size="lg">Publish iteration</flux:heading><flux:text class="mt-2">Generation produces a reviewable bundle first. Git, ZIP and Docker publishing are enabled in the packaging phase after a successful debug run.</flux:text></div>
    @endif
</div>
