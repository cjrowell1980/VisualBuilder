<?php

use App\Models\BuilderProject;
use App\Models\BuildIteration;
use App\Models\ModelField;
use App\Services\Assembly\LaravelProjectAssembler;
use App\Services\Debugging\IterationValidator;
use App\Services\Debugging\PreviewServerManager;
use App\Services\Generation\LaravelArtifactGenerator;
use App\Services\Iterations\IterationCloner;
use App\Services\Packaging\IterationPackager;
use App\Services\Publishing\GitHubPublisher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public BuilderProject $project;

    public string $mode = 'schema';

    public string $modelName = '';

    public string $modelTableName = '';

    public bool $modelSoftDeletes = false;

    public bool $modelTimestamps = true;

    public ?int $editingModelId = null;

    public string $fieldName = '';

    public string $fieldLabel = '';

    public string $fieldType = 'string';

    public string $fieldRules = '';

    public string $fieldDefault = '';

    public bool $fieldNullable = false;

    public bool $fieldIndexed = false;

    public bool $fieldUnique = false;

    public ?int $editingFieldId = null;

    public ?int $selectedModelId = null;

    public string $relationshipName = '';

    public string $relationshipType = 'belongsTo';

    public ?int $relationshipTargetId = null;

    public ?int $editingRelationshipId = null;

    public string $pageName = '';

    public string $pageSlug = '';

    public string $pageType = 'custom';

    public ?int $pageModelId = null;

    public ?int $selectedPageId = null;

    public ?int $editingPageId = null;

    public string $controlType = 'input';

    public string $controlLabel = '';

    public string $controlWidth = 'full';

    public string $controlOptions = '';

    public ?int $controlFieldId = null;

    public ?int $editingControlId = null;

    public ?string $generatedPath = null;

    public string $iterationName = '';

    public ?string $packagePath = null;

    public ?string $applicationPackagePath = null;

    public ?string $assemblyMessage = null;

    public ?string $previewMessage = null;

    public string $githubRepository = '';

    public ?string $githubMessage = null;

    public string $projectOutputPath = '';

    public bool $projectDockerEnabled = false;

    public string $pluginPackage = '';

    public string $pluginConstraint = '*';

    public string $pluginType = 'composer';

    public function mount(BuilderProject $project): void
    {
        abort_unless($project->user_id === auth()->id(), 403);
        $this->project = $project;
        $this->githubRepository = (string) $project->github_repository;
        $this->projectOutputPath = (string) $project->output_path;
        $this->projectDockerEnabled = (bool) $project->docker_enabled;
    }

    public function setMode(string $mode): void
    {
        abort_unless(in_array($mode, ['schema', 'pages', 'preview', 'publish'], true), 404);
        $this->mode = $mode;
    }

    public function addModel(): void
    {
        $data = $this->validate([
            'modelName' => ['required', 'regex:/^[A-Z][A-Za-z0-9]*$/', 'max:80'],
            'modelTableName' => ['nullable', 'regex:/^[a-z][a-z0-9_]*$/', 'max:80'],
            'modelSoftDeletes' => ['boolean'],
            'modelTimestamps' => ['boolean'],
        ]);
        $model = $this->iteration()->models()->create([
            'name' => $data['modelName'],
            'table_name' => $data['modelTableName'] ?: Str::snake(Str::pluralStudly($data['modelName'])),
            'soft_deletes' => $data['modelSoftDeletes'],
            'timestamps' => $data['modelTimestamps'],
        ]);
        $this->selectedModelId = $model->id;
        $this->resetModelEditor();
        $this->touchDesign();
    }

    public function editModel(int $modelId): void
    {
        $model = $this->iteration()->models()->findOrFail($modelId);
        $this->editingModelId = $model->id;
        $this->modelName = $model->name;
        $this->modelTableName = $model->table_name;
        $this->modelSoftDeletes = $model->soft_deletes;
        $this->modelTimestamps = (bool) $model->getAttribute('timestamps');
    }

    public function saveModel(): void
    {
        $iteration = $this->iteration();
        $model = $iteration->models()->findOrFail($this->editingModelId);
        $data = $this->validate([
            'modelName' => ['required', 'regex:/^[A-Z][A-Za-z0-9]*$/', 'max:80', Rule::unique('model_definitions', 'name')->where('build_iteration_id', $iteration->id)->ignore($model->id)],
            'modelTableName' => ['required', 'regex:/^[a-z][a-z0-9_]*$/', 'max:80', Rule::unique('model_definitions', 'table_name')->where('build_iteration_id', $iteration->id)->ignore($model->id)],
            'modelSoftDeletes' => ['boolean'],
            'modelTimestamps' => ['boolean'],
        ]);
        $model->update([
            'name' => $data['modelName'],
            'table_name' => $data['modelTableName'],
            'soft_deletes' => $data['modelSoftDeletes'],
            'timestamps' => $data['modelTimestamps'],
        ]);
        $this->cancelModelEdit();
        $this->touchDesign();
    }

    public function cancelModelEdit(): void
    {
        $this->resetModelEditor();
    }

    private function resetModelEditor(): void
    {
        $this->reset('editingModelId', 'modelName', 'modelTableName', 'modelSoftDeletes');
        $this->modelTimestamps = true;
    }

    public function addField(): void
    {
        $model = $this->iteration()->models()->findOrFail($this->selectedModelId);
        $data = $this->validate([
            'fieldName' => ['required', 'regex:/^[a-z][a-z0-9_]*$/', 'max:64', Rule::unique('model_fields', 'name')->where('model_definition_id', $model->id)],
            'fieldLabel' => ['nullable', 'string', 'max:100'],
            'fieldType' => ['required', Rule::in(['string', 'text', 'integer', 'boolean', 'date', 'datetime', 'decimal', 'json'])],
            'fieldRules' => ['nullable', 'string', 'max:500'],
            'fieldDefault' => ['nullable', 'string', 'max:500'],
            'fieldNullable' => ['boolean'],
            'fieldIndexed' => ['boolean'],
            'fieldUnique' => ['boolean'],
        ]);
        if (! $this->validateFieldDefault($data['fieldType'], $data['fieldDefault'])) {
            return;
        }
        $model->fields()->create([
            'name' => $data['fieldName'],
            'label' => $data['fieldLabel'] ?: Str::headline($data['fieldName']),
            'type' => $data['fieldType'],
            'validation_rules' => array_values(array_filter(array_map('trim', explode('|', $data['fieldRules'])))),
            'default_value' => trim($data['fieldDefault']) === '' ? null : trim($data['fieldDefault']),
            'nullable' => $data['fieldNullable'],
            'indexed' => $data['fieldIndexed'],
            'unique' => $data['fieldUnique'],
            'position' => $model->fields()->count(),
        ]);
        $this->reset('fieldName', 'fieldLabel', 'fieldRules', 'fieldDefault', 'fieldNullable', 'fieldIndexed', 'fieldUnique');
        $this->touchDesign();
    }

    public function editField(int $fieldId): void
    {
        $model = $this->iteration()->models()->findOrFail($this->selectedModelId);
        $field = $model->fields()->findOrFail($fieldId);
        $this->editingFieldId = $field->id;
        $this->fieldName = $field->name;
        $this->fieldLabel = (string) $field->label;
        $this->fieldType = $field->type;
        $this->fieldRules = implode('|', $field->validation_rules ?? []);
        $this->fieldDefault = (string) $field->default_value;
        $this->fieldNullable = $field->nullable;
        $this->fieldIndexed = $field->indexed;
        $this->fieldUnique = $field->unique;
    }

    public function saveField(): void
    {
        $model = $this->iteration()->models()->findOrFail($this->selectedModelId);
        $field = $model->fields()->findOrFail($this->editingFieldId);
        $data = $this->validate([
            'fieldName' => ['required', 'regex:/^[a-z][a-z0-9_]*$/', 'max:64', Rule::unique('model_fields', 'name')->where('model_definition_id', $model->id)->ignore($field->id)],
            'fieldLabel' => ['nullable', 'string', 'max:100'],
            'fieldType' => ['required', Rule::in(['string', 'text', 'integer', 'boolean', 'date', 'datetime', 'decimal', 'json'])],
            'fieldRules' => ['nullable', 'string', 'max:500'],
            'fieldDefault' => ['nullable', 'string', 'max:500'],
            'fieldNullable' => ['boolean'],
            'fieldIndexed' => ['boolean'],
            'fieldUnique' => ['boolean'],
        ]);
        if (! $this->validateFieldDefault($data['fieldType'], $data['fieldDefault'])) {
            return;
        }
        $field->update([
            'name' => $data['fieldName'],
            'label' => $data['fieldLabel'] ?: Str::headline($data['fieldName']),
            'type' => $data['fieldType'],
            'validation_rules' => array_values(array_filter(array_map('trim', explode('|', $data['fieldRules'])))),
            'default_value' => trim($data['fieldDefault']) === '' ? null : trim($data['fieldDefault']),
            'nullable' => $data['fieldNullable'],
            'indexed' => $data['fieldIndexed'],
            'unique' => $data['fieldUnique'],
        ]);
        $this->cancelFieldEdit();
        $this->touchDesign();
    }

    public function cancelFieldEdit(): void
    {
        $this->reset('editingFieldId', 'fieldName', 'fieldLabel', 'fieldRules', 'fieldDefault', 'fieldNullable', 'fieldIndexed', 'fieldUnique');
        $this->fieldType = 'string';
    }

    public function addRelationship(): void
    {
        $source = $this->iteration()->models()->findOrFail($this->selectedModelId);
        $data = $this->validate([
            'relationshipName' => ['required', 'regex:/^[a-z][A-Za-z0-9]*$/', 'max:80', Rule::unique('model_relationships', 'name')->where('source_model_id', $source->id)],
            'relationshipType' => ['required', Rule::in(['belongsTo', 'hasOne', 'hasMany', 'belongsToMany'])],
            'relationshipTargetId' => ['required', Rule::exists('model_definitions', 'id')->where('build_iteration_id', $source->build_iteration_id)],
        ]);
        $target = $this->iteration()->models()->findOrFail($data['relationshipTargetId']);
        $source->relationships()->create([
            'target_model_id' => $target->id,
            'name' => $data['relationshipName'],
            'type' => $data['relationshipType'],
            'foreign_key' => $data['relationshipType'] === 'belongsTo' ? Str::snake($data['relationshipName']).'_id' : null,
        ]);
        $this->resetRelationshipEditor();
        $this->touchDesign();
    }

    public function editRelationship(int $relationshipId): void
    {
        $source = $this->iteration()->models()->findOrFail($this->selectedModelId);
        $relationship = $source->relationships()->findOrFail($relationshipId);
        $this->editingRelationshipId = $relationship->id;
        $this->relationshipName = $relationship->name;
        $this->relationshipType = $relationship->type;
        $this->relationshipTargetId = $relationship->target_model_id;
    }

    public function saveRelationship(): void
    {
        $source = $this->iteration()->models()->findOrFail($this->selectedModelId);
        $relationship = $source->relationships()->findOrFail($this->editingRelationshipId);
        $data = $this->validate([
            'relationshipName' => ['required', 'regex:/^[a-z][A-Za-z0-9]*$/', 'max:80', Rule::unique('model_relationships', 'name')->where('source_model_id', $source->id)->ignore($relationship->id)],
            'relationshipType' => ['required', Rule::in(['belongsTo', 'hasOne', 'hasMany', 'belongsToMany'])],
            'relationshipTargetId' => ['required', Rule::exists('model_definitions', 'id')->where('build_iteration_id', $source->build_iteration_id)],
        ]);
        $relationship->update([
            'target_model_id' => $data['relationshipTargetId'],
            'name' => $data['relationshipName'],
            'type' => $data['relationshipType'],
            'foreign_key' => $data['relationshipType'] === 'belongsTo' ? Str::snake($data['relationshipName']).'_id' : null,
        ]);
        $this->resetRelationshipEditor();
        $this->touchDesign();
    }

    public function deleteRelationship(int $relationshipId): void
    {
        $source = $this->iteration()->models()->findOrFail($this->selectedModelId);
        $source->relationships()->findOrFail($relationshipId)->delete();
        $this->resetRelationshipEditor();
        $this->touchDesign();
    }

    public function cancelRelationshipEdit(): void
    {
        $this->resetRelationshipEditor();
    }

    private function resetRelationshipEditor(): void
    {
        $this->reset('editingRelationshipId', 'relationshipName', 'relationshipTargetId');
        $this->relationshipType = 'belongsTo';
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
        $this->touchDesign();
    }

    public function editPage(int $pageId): void
    {
        $page = $this->iteration()->pages()->findOrFail($pageId);
        $this->editingPageId = $page->id;
        $this->pageName = $page->name;
        $this->pageSlug = $page->slug;
        $this->pageType = $page->page_type;
        $this->pageModelId = $page->model_definition_id;
    }

    public function savePage(): void
    {
        $iteration = $this->iteration();
        $page = $iteration->pages()->findOrFail($this->editingPageId);
        $data = $this->validate([
            'pageName' => ['required', 'string', 'max:100'],
            'pageSlug' => ['required', 'regex:/^[a-z0-9]+(?:[\/-][a-z0-9]+)*$/', 'max:120', Rule::unique('page_definitions', 'slug')->where('build_iteration_id', $iteration->id)->ignore($page->id)],
            'pageType' => ['required', Rule::in(['custom', 'index', 'create', 'edit', 'show', 'dashboard'])],
            'pageModelId' => ['nullable', 'integer'],
        ]);
        $modelId = $data['pageModelId'] ? $iteration->models()->findOrFail($data['pageModelId'])->id : null;
        $page->update([
            'model_definition_id' => $modelId,
            'name' => $data['pageName'],
            'slug' => $data['pageSlug'],
            'page_type' => $data['pageType'],
        ]);
        $this->cancelPageEdit();
        $this->touchDesign();
    }

    public function cancelPageEdit(): void
    {
        $this->reset('editingPageId', 'pageName', 'pageSlug', 'pageModelId');
        $this->pageType = 'custom';
    }

    public function addControl(): void
    {
        $page = $this->iteration()->pages()->findOrFail($this->selectedPageId);
        $fieldId = null;
        if ($this->controlFieldId) {
            $field = ModelField::query()
                ->whereKey($this->controlFieldId)
                ->whereHas('modelDefinition', fn ($query) => $query->where('build_iteration_id', $this->iteration()->id))
                ->firstOrFail();
            if ($page->model_definition_id && $field->model_definition_id !== $page->model_definition_id) {
                $this->addError('controlFieldId', 'Choose a field belonging to the page model.');

                return;
            }
            $fieldId = $field->id;
        }
        $data = $this->validate([
            'controlType' => ['required', Rule::in(['heading', 'text', 'input', 'textarea', 'select', 'checkbox', 'button', 'table'])],
            'controlLabel' => ['nullable', 'string', 'max:100'],
            'controlWidth' => ['required', Rule::in(['full', 'half', 'third', 'two-thirds'])],
            'controlOptions' => ['nullable', 'string', 'max:2000'],
        ]);
        $page->controls()->create([
            'model_field_id' => $fieldId,
            'control_type' => $data['controlType'],
            'label' => $data['controlLabel'] ?: Str::headline($data['controlType']),
            'width' => $data['controlWidth'],
            'configuration' => ['options' => $this->parseControlOptions($data['controlOptions'])],
            'position' => $page->controls()->count(),
        ]);
        $this->cancelControlEdit();
        $this->touchDesign();
    }

    public function editControl(int $controlId): void
    {
        $page = $this->iteration()->pages()->findOrFail($this->selectedPageId);
        $control = $page->controls()->findOrFail($controlId);
        $this->editingControlId = $control->id;
        $this->controlType = $control->control_type;
        $this->controlLabel = (string) $control->label;
        $this->controlFieldId = $control->model_field_id;
        $this->controlWidth = $control->width;
        $this->controlOptions = collect($control->configuration['options'] ?? [])
            ->map(fn (array $option): string => $option['value'].':'.$option['label'])
            ->implode("\n");
    }

    public function saveControl(): void
    {
        $page = $this->iteration()->pages()->findOrFail($this->selectedPageId);
        $control = $page->controls()->findOrFail($this->editingControlId);
        $fieldId = null;
        if ($this->controlFieldId) {
            $field = ModelField::query()
                ->whereKey($this->controlFieldId)
                ->whereHas('modelDefinition', fn ($query) => $query->where('build_iteration_id', $this->iteration()->id))
                ->firstOrFail();
            if ($page->model_definition_id && $field->model_definition_id !== $page->model_definition_id) {
                $this->addError('controlFieldId', 'Choose a field belonging to the page model.');

                return;
            }
            $fieldId = $field->id;
        }
        $data = $this->validate([
            'controlType' => ['required', Rule::in(['heading', 'text', 'input', 'textarea', 'select', 'checkbox', 'button', 'table'])],
            'controlLabel' => ['nullable', 'string', 'max:100'],
            'controlWidth' => ['required', Rule::in(['full', 'half', 'third', 'two-thirds'])],
            'controlOptions' => ['nullable', 'string', 'max:2000'],
        ]);
        $control->update([
            'model_field_id' => $fieldId,
            'control_type' => $data['controlType'],
            'label' => $data['controlLabel'] ?: Str::headline($data['controlType']),
            'width' => $data['controlWidth'],
            'configuration' => ['options' => $this->parseControlOptions($data['controlOptions'])],
        ]);
        $this->cancelControlEdit();
        $this->touchDesign();
    }

    public function cancelControlEdit(): void
    {
        $this->reset('editingControlId', 'controlLabel', 'controlFieldId', 'controlOptions');
        $this->controlType = 'input';
        $this->controlWidth = 'full';
    }

    public function generate(LaravelArtifactGenerator $generator): void
    {
        $result = $generator->generate($this->iteration());
        $this->generatedPath = $result['path'];
    }

    public function runValidation(IterationValidator $validator): void
    {
        $validator->run($this->iteration());
    }

    public function packageIteration(IterationPackager $packager): void
    {
        $package = $packager->zip($this->iteration());
        $this->packagePath = $package->path;
    }

    public function packageApplication(IterationPackager $packager): void
    {
        $package = $packager->zipApplication($this->iteration());
        $this->applicationPackagePath = $package->path;
    }

    public function assembleProject(LaravelProjectAssembler $assembler): void
    {
        $run = $assembler->assemble($this->iteration());
        $this->assemblyMessage = $run->checks[0]['message'] ?? $run->output;
    }

    public function startPreview(PreviewServerManager $preview): void
    {
        $run = $preview->start($this->iteration());
        $this->previewMessage = $run->checks[0]['message'] ?? $run->output;
    }

    public function openPreview(PreviewServerManager $preview): void
    {
        $preview->open($this->iteration());
    }

    public function stopPreview(PreviewServerManager $preview): void
    {
        $preview->stop($this->iteration());
        $this->previewMessage = 'Debugger stopped.';
    }

    public function publishToGitHub(GitHubPublisher $publisher): void
    {
        $data = $this->validate([
            'githubRepository' => ['required', 'regex:/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', 'max:200'],
        ]);
        $run = $publisher->publish($this->iteration(), $data['githubRepository']);
        $this->githubMessage = $run->checks[0]['message'] ?? $run->output;
        $this->project->refresh();
    }

    public function saveProjectSettings(): void
    {
        $data = $this->validate([
            'projectOutputPath' => ['required', 'string', 'max:500'],
            'projectDockerEnabled' => ['boolean'],
        ]);
        $this->project->update([
            'output_path' => $data['projectOutputPath'],
            'docker_enabled' => $data['projectDockerEnabled'],
        ]);
        $this->touchDesign();
    }

    public function addPlugin(): void
    {
        $data = $this->validate([
            'pluginPackage' => ['required', 'string', 'max:200', 'regex:/^@?[a-z0-9_.-]+(?:\/[a-z0-9_.-]+)?$/i'],
            'pluginConstraint' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9.*^~<>=| -]+$/'],
            'pluginType' => ['required', Rule::in(['composer', 'npm'])],
        ]);
        if ($data['pluginType'] === 'composer' && ! preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/i', $data['pluginPackage'])) {
            $this->addError('pluginPackage', 'Composer packages must use vendor/package format.');

            return;
        }
        $this->iteration()->plugins()->create([
            'package' => $data['pluginPackage'],
            'constraint' => $data['pluginConstraint'],
            'type' => $data['pluginType'],
            'approved' => false,
        ]);
        $this->reset('pluginPackage');
        $this->pluginConstraint = '*';
        $this->touchDesign();
    }

    public function togglePluginApproval(int $pluginId): void
    {
        $plugin = $this->iteration()->plugins()->findOrFail($pluginId);
        $plugin->update(['approved' => ! $plugin->approved]);
        $this->touchDesign();
    }

    public function removePlugin(int $pluginId): void
    {
        $this->iteration()->plugins()->findOrFail($pluginId)->delete();
        $this->touchDesign();
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
        $this->cancelModelEdit();
        $this->cancelFieldEdit();
    }

    public function selectPage(int $pageId): void
    {
        $this->iteration()->pages()->findOrFail($pageId);
        $this->selectedPageId = $pageId;
        $this->cancelControlEdit();
    }

    public function deleteModel(int $modelId): void
    {
        $this->iteration()->models()->findOrFail($modelId)->delete();
        $this->selectedModelId = null;
        $this->touchDesign();
    }

    public function deleteField(int $fieldId): void
    {
        $model = $this->iteration()->models()->findOrFail($this->selectedModelId);
        $model->fields()->findOrFail($fieldId)->delete();
        $this->touchDesign();
    }

    public function moveField(int $fieldId, string $direction): void
    {
        $model = $this->iteration()->models()->findOrFail($this->selectedModelId);
        $field = $model->fields()->findOrFail($fieldId);
        $this->swapPosition($field, $model->fields(), $direction);
    }

    public function deletePage(int $pageId): void
    {
        $this->iteration()->pages()->findOrFail($pageId)->delete();
        $this->selectedPageId = null;
        $this->touchDesign();
    }

    public function movePage(int $pageId, string $direction): void
    {
        $iteration = $this->iteration();
        $page = $iteration->pages()->findOrFail($pageId);
        $this->swapPosition($page, $iteration->pages(), $direction);
    }

    public function deleteControl(int $controlId): void
    {
        $page = $this->iteration()->pages()->findOrFail($this->selectedPageId);
        $page->controls()->findOrFail($controlId)->delete();
        $this->touchDesign();
    }

    public function moveControl(int $controlId, string $direction): void
    {
        $page = $this->iteration()->pages()->findOrFail($this->selectedPageId);
        $control = $page->controls()->findOrFail($controlId);
        $this->swapPosition($control, $page->controls(), $direction);
    }

    public function with(): array
    {
        $iteration = $this->iteration()->load('models.fields', 'models.relationships.target', 'pages.controls.field', 'pages.modelDefinition', 'plugins', 'runs', 'packages');
        $this->selectedModelId ??= $iteration->models->first()?->id;
        $this->selectedPageId ??= $iteration->pages->first()?->id;

        return [
            'iteration' => $iteration,
            'iterations' => $this->project->iterations()->latest('number')->get(),
            'validationRun' => $iteration->runs->firstWhere('type', 'validation'),
            'assemblyRun' => $iteration->runs->firstWhere('type', 'assembly'),
            'previewRun' => $iteration->runs->firstWhere('type', 'preview'),
            'githubRun' => $iteration->runs->firstWhere('type', 'github'),
        ];
    }

    private function iteration(): BuildIteration
    {
        return $this->project->iterations()->latest('number')->firstOrFail();
    }

    private function touchDesign(): void
    {
        $this->iteration()->update(['status' => 'draft', 'generated_at' => null]);
    }

    private function swapPosition(Model $item, HasMany $relation, string $direction): void
    {
        abort_unless(in_array($direction, ['up', 'down'], true), 404);
        $operator = $direction === 'up' ? '<' : '>';
        $order = $direction === 'up' ? 'desc' : 'asc';
        $adjacent = $relation->where('position', $operator, $item->getAttribute('position'))->orderBy('position', $order)->first();
        if ($adjacent) {
            $position = $item->getAttribute('position');
            $item->update(['position' => $adjacent->getAttribute('position')]);
            $adjacent->update(['position' => $position]);
            $this->touchDesign();
        }
    }

    /** @return list<array{value: string, label: string}> */
    private function parseControlOptions(string $options): array
    {
        return collect(preg_split('/\r\n|\r|\n/', trim($options)) ?: [])
            ->filter()
            ->map(function (string $line): array {
                [$value, $label] = array_pad(array_map('trim', explode(':', $line, 2)), 2, null);

                return ['value' => $value, 'label' => $label ?: Str::headline($value)];
            })
            ->values()
            ->all();
    }

    private function validateFieldDefault(string $type, string $default): bool
    {
        $default = trim($default);
        if ($default === '') {
            return true;
        }
        $valid = match ($type) {
            'integer', 'decimal' => is_numeric($default),
            'boolean' => in_array(strtolower($default), ['true', 'false', '1', '0'], true),
            'json' => false,
            default => true,
        };
        if (! $valid) {
            $message = $type === 'json'
                ? 'JSON defaults are not portable across the supported databases.'
                : "Enter a valid {$type} default value.";
            $this->addError('fieldDefault', $message);
        }

        return $valid;
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
        <div class="flex gap-2"><flux:dropdown position="bottom" align="end"><flux:button icon="clock" icon:trailing="chevron-down">{{ $iterations->count() }} iteration{{ $iterations->count() === 1 ? '' : 's' }}</flux:button><flux:menu>@foreach($iterations as $history)<div class="min-w-64 px-3 py-2"><div class="flex items-center justify-between gap-4 text-sm"><span>Iteration {{ $history->number }} · {{ $history->name }}</span><flux:badge :color="$history->status === 'generated' ? 'green' : ($history->status === 'validated' ? 'blue' : 'zinc')">{{ $history->status }}</flux:badge></div><div class="mt-1 text-xs text-zinc-500">{{ $history->created_at->format('j M Y, H:i') }}</div></div>@if(!$loop->last)<flux:menu.separator />@endif @endforeach</flux:menu></flux:dropdown><flux:modal.trigger name="project-settings"><flux:button icon="cog-6-tooth">Settings</flux:button></flux:modal.trigger><flux:modal.trigger name="new-iteration"><flux:button icon="document-duplicate">New iteration</flux:button></flux:modal.trigger><flux:button wire:click="generate" variant="primary" icon="code-bracket" :disabled="$iteration->status !== 'validated'">Generate iteration</flux:button></div>
    </header>

    <nav class="flex gap-1 border-b border-zinc-200 pb-2 dark:border-zinc-700">
        @foreach (['schema' => 'Data', 'pages' => 'Pages', 'preview' => 'Preview', 'publish' => 'Publish'] as $key => $label)
            <flux:button wire:click="setMode('{{ $key }}')" size="sm" :variant="$mode === $key ? 'primary' : 'ghost'">{{ $label }}</flux:button>
        @endforeach
    </nav>

    @if ($generatedPath)<flux:callout variant="success" icon="check-circle" heading="Iteration generated"><flux:callout.text>{{ $generatedPath }}</flux:callout.text></flux:callout>@endif

    <flux:modal name="new-iteration" class="md:w-96"><form wire:submit="createIteration" class="space-y-5"><div><flux:heading size="lg">Create iteration</flux:heading><flux:text class="mt-1">Clone the current design into a new editable version.</flux:text></div><flux:input wire:model="iterationName" label="Iteration name" placeholder="Add customer approvals" /><div class="flex justify-end gap-2"><flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close><flux:button type="submit" variant="primary">Create iteration</flux:button></div></form></flux:modal>

    <flux:modal name="project-settings" class="md:w-[32rem]"><form wire:submit="saveProjectSettings" class="space-y-5"><div><flux:heading size="lg">Project settings</flux:heading><flux:text class="mt-1">Choose a new, empty output path for each assembled build.</flux:text></div><flux:input wire:model="projectOutputPath" label="Application output folder" placeholder="C:\Projects\my-app" /><flux:checkbox wire:model="projectDockerEnabled" label="Include Docker environment" /><div class="flex justify-end gap-2"><flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close><flux:button type="submit" variant="primary">Save settings</flux:button></div></form></flux:modal>

    @if ($mode === 'schema')
        <div class="grid min-h-[40rem] overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 lg:grid-cols-[17rem_1fr_21rem]">
            <aside class="border-r border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading size="sm">Models</flux:heading>
                <div class="mt-4 space-y-1">@foreach ($iteration->models as $model)<button type="button" wire:click="selectModel({{ $model->id }})" class="flex w-full justify-between rounded-lg px-3 py-2 text-left text-sm {{ $selectedModelId === $model->id ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-200' : 'hover:bg-zinc-100 dark:hover:bg-zinc-800' }}"><span>{{ $model->name }}</span><span>{{ $model->fields->count() }}</span></button>@endforeach</div>
                <form wire:submit="{{ $editingModelId ? 'saveModel' : 'addModel' }}" class="mt-5 space-y-3"><flux:input wire:model="modelName" label="Model" placeholder="Customer" /><flux:input wire:model="modelTableName" label="Table name" placeholder="customers" /><div class="space-y-2"><flux:checkbox wire:model="modelTimestamps" label="Created and updated timestamps" /><flux:checkbox wire:model="modelSoftDeletes" label="Soft deletes" /></div><div class="flex gap-2"><flux:button type="submit" size="sm" class="flex-1">{{ $editingModelId ? 'Save model' : 'Add model' }}</flux:button>@if($editingModelId)<flux:button wire:click="cancelModelEdit" type="button" size="sm" variant="ghost">Cancel</flux:button>@endif</div></form>
            </aside>
            <main class="p-6">
                @php($selected = $iteration->models->firstWhere('id', $selectedModelId))
                @if ($selected)
                    <div class="flex justify-between"><div><flux:heading>{{ $selected->name }}</flux:heading><flux:text>{{ $selected->table_name }}</flux:text></div><div class="flex items-center gap-2"><flux:badge>{{ $selected->getAttribute('timestamps') ? 'timestamps' : 'no timestamps' }}</flux:badge>@if($selected->soft_deletes)<flux:badge color="amber">soft deletes</flux:badge>@endif<flux:badge>{{ $selected->fields->count() }} fields</flux:badge><flux:button wire:click="editModel({{ $selected->id }})" size="sm" variant="ghost" icon="pencil-square" /><flux:button wire:click="deleteModel({{ $selected->id }})" wire:confirm="Delete this model and its fields and relationships?" size="sm" variant="danger" icon="trash" /></div></div>
                    <div class="mt-6 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">@forelse ($selected->fields as $field)<div class="grid grid-cols-[1fr_7rem_8rem_auto] items-center gap-3 border-b border-zinc-100 px-4 py-3 text-sm last:border-0 dark:border-zinc-800"><div><div>{{ $field->label }}</div><code class="text-xs">{{ $field->name }}</code></div><span>{{ $field->type }}</span><span class="text-xs text-zinc-500">{{ $field->nullable ? 'nullable' : 'required' }}{{ $field->unique ? ' · unique' : '' }}</span><div class="flex gap-1"><flux:button wire:click="editField({{ $field->id }})" size="xs" variant="ghost" icon="pencil-square" /><flux:button wire:click="moveField({{ $field->id }}, 'up')" size="xs" variant="ghost" icon="arrow-up" /><flux:button wire:click="moveField({{ $field->id }}, 'down')" size="xs" variant="ghost" icon="arrow-down" /><flux:button wire:click="deleteField({{ $field->id }})" wire:confirm="Delete this field?" size="xs" variant="danger" icon="trash" /></div></div>@empty<div class="p-10 text-center text-sm text-zinc-500">Add the first field using the inspector.</div>@endforelse</div>
                    <div class="mt-7"><flux:heading size="sm">Relationships</flux:heading><div class="mt-3 space-y-2">@forelse($selected->relationships as $relationship)<div class="flex items-center justify-between rounded-lg bg-zinc-50 px-3 py-2 text-sm dark:bg-zinc-800"><code>{{ $relationship->name }}()</code><div class="flex items-center gap-2"><span>{{ $relationship->type }} {{ $relationship->target->name }}</span><flux:button wire:click="editRelationship({{ $relationship->id }})" size="xs" variant="ghost" icon="pencil-square" /><flux:button wire:click="deleteRelationship({{ $relationship->id }})" wire:confirm="Delete this relationship?" size="xs" variant="danger" icon="trash" /></div></div>@empty<flux:text>No relationships defined.</flux:text>@endforelse</div></div>
                @else<div class="grid h-full place-items-center"><flux:heading>Start with a model</flux:heading></div>@endif
            </main>
            <aside class="border-l border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading size="sm">Field inspector</flux:heading>
                <form wire:submit="{{ $editingFieldId ? 'saveField' : 'addField' }}" class="mt-4 space-y-3"><flux:input wire:model="fieldName" label="Name" placeholder="email_address" /><flux:input wire:model="fieldLabel" label="Label" placeholder="Email address" /><flux:select wire:model="fieldType" label="Type">@foreach (['string','text','integer','boolean','date','datetime','decimal','json'] as $type)<flux:select.option value="{{ $type }}">{{ ucfirst($type) }}</flux:select.option>@endforeach</flux:select><flux:input wire:model="fieldRules" label="Validation rules" placeholder="required|email|max:255" /><flux:input wire:model="fieldDefault" label="Default value" placeholder="Optional" /><div class="flex flex-wrap gap-4"><flux:checkbox wire:model="fieldNullable" label="Nullable" /><flux:checkbox wire:model="fieldIndexed" label="Index" /><flux:checkbox wire:model="fieldUnique" label="Unique" /></div><div class="flex gap-2"><flux:button type="submit" class="flex-1" :disabled="!$selectedModelId">{{ $editingFieldId ? 'Save field' : 'Add field' }}</flux:button>@if($editingFieldId)<flux:button wire:click="cancelFieldEdit" type="button" variant="ghost">Cancel</flux:button>@endif</div></form>
                <flux:separator class="my-6" />
                <flux:heading size="sm">Relationship</flux:heading>
                <form wire:submit="{{ $editingRelationshipId ? 'saveRelationship' : 'addRelationship' }}" class="mt-4 space-y-3"><flux:input wire:model="relationshipName" label="Method name" placeholder="customer" /><flux:select wire:model="relationshipType" label="Type">@foreach(['belongsTo','hasOne','hasMany','belongsToMany'] as $type)<flux:select.option value="{{ $type }}">{{ $type }}</flux:select.option>@endforeach</flux:select><flux:select wire:model="relationshipTargetId" label="Target model"><flux:select.option value="">Select model</flux:select.option>@foreach($iteration->models as $model)<flux:select.option value="{{ $model->id }}">{{ $model->name }}</flux:select.option>@endforeach</flux:select><div class="flex gap-2"><flux:button type="submit" class="flex-1" :disabled="!$selectedModelId">{{ $editingRelationshipId ? 'Save relationship' : 'Add relationship' }}</flux:button>@if($editingRelationshipId)<flux:button wire:click="cancelRelationshipEdit" type="button" variant="ghost">Cancel</flux:button>@endif</div></form>
            </aside>
        </div>
    @elseif ($mode === 'pages')
        @if($project->template === 'api')<flux:callout variant="warning" icon="information-circle" heading="API-only project"><flux:callout.text>Pages are optional. Models generate authenticated Sanctum CRUD endpoints even when the visual canvas is empty.</flux:callout.text></flux:callout>@endif
        <div class="grid min-h-[40rem] overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 lg:grid-cols-[18rem_1fr_21rem]">
            <aside class="border-r border-zinc-200 p-5 dark:border-zinc-700"><flux:heading size="sm">Pages</flux:heading><div class="mt-4 space-y-1">@foreach($iteration->pages as $page)<button type="button" wire:click="selectPage({{ $page->id }})" class="flex w-full justify-between rounded-lg px-3 py-2 text-left text-sm {{ $selectedPageId === $page->id ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-200' : 'hover:bg-zinc-100 dark:hover:bg-zinc-800' }}"><span>{{ $page->name }}</span><span>{{ $page->controls->count() }}</span></button>@endforeach</div><form wire:submit="{{ $editingPageId ? 'savePage' : 'addPage' }}" class="mt-5 space-y-3"><flux:input wire:model="pageName" label="Page name" placeholder="Customers" /><flux:input wire:model="pageSlug" label="URL" placeholder="customers" /><flux:select wire:model="pageType" label="Type">@foreach(['custom','dashboard','index','create','edit','show'] as $type)<flux:select.option value="{{ $type }}">{{ ucfirst($type) }}</flux:select.option>@endforeach</flux:select><flux:select wire:model="pageModelId" label="Model (optional)"><flux:select.option value="">None</flux:select.option>@foreach($iteration->models as $model)<flux:select.option value="{{ $model->id }}">{{ $model->name }}</flux:select.option>@endforeach</flux:select><div class="flex gap-2"><flux:button type="submit" class="flex-1">{{ $editingPageId ? 'Save page' : 'Add page' }}</flux:button>@if($editingPageId)<flux:button wire:click="cancelPageEdit" type="button" variant="ghost">Cancel</flux:button>@endif</div></form></aside>
            <main class="bg-zinc-50 p-6 dark:bg-zinc-950">@php($selectedPage = $iteration->pages->firstWhere('id', $selectedPageId))@if($selectedPage)<div class="min-h-full rounded-xl border border-zinc-200 bg-white p-7 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"><div class="mb-6 flex justify-between"><div><flux:heading size="xl">{{ $selectedPage->name }}</flux:heading><flux:text>/{{ $selectedPage->slug }} · {{ $selectedPage->page_type }}</flux:text></div><div class="flex items-center gap-2"><flux:badge>{{ $selectedPage->modelDefinition?->name ?? 'No model' }}</flux:badge><flux:button wire:click="editPage({{ $selectedPage->id }})" size="sm" variant="ghost" icon="pencil-square" /><flux:button wire:click="movePage({{ $selectedPage->id }}, 'up')" size="sm" variant="ghost" icon="arrow-up" /><flux:button wire:click="movePage({{ $selectedPage->id }}, 'down')" size="sm" variant="ghost" icon="arrow-down" /><flux:button wire:click="deletePage({{ $selectedPage->id }})" wire:confirm="Delete this page and all its controls?" size="sm" variant="danger" icon="trash" /></div></div><div class="space-y-4">@forelse($selectedPage->controls as $control)<div class="rounded-lg border border-dashed border-zinc-300 p-4 dark:border-zinc-700"><div class="flex justify-between gap-3"><div><span>{{ $control->label }}</span>@if($control->field)<flux:text class="mt-1">Bound to {{ $control->field->name }}</flux:text>@endif</div><div class="flex items-center gap-1"><code class="mr-2 text-xs">{{ $control->control_type }}</code><flux:button wire:click="editControl({{ $control->id }})" size="xs" variant="ghost" icon="pencil-square" /><flux:button wire:click="moveControl({{ $control->id }}, 'up')" size="xs" variant="ghost" icon="arrow-up" /><flux:button wire:click="moveControl({{ $control->id }}, 'down')" size="xs" variant="ghost" icon="arrow-down" /><flux:button wire:click="deleteControl({{ $control->id }})" wire:confirm="Delete this control?" size="xs" variant="danger" icon="trash" /></div></div></div>@empty<div class="grid min-h-64 place-items-center text-center"><div><flux:heading>Empty canvas</flux:heading><flux:text>Add controls from the inspector.</flux:text></div></div>@endforelse</div></div>@else<div class="grid h-full place-items-center"><flux:heading>Create or select a page</flux:heading></div>@endif</main>
            <aside class="border-l border-zinc-200 p-5 dark:border-zinc-700"><flux:heading size="sm">Control inspector</flux:heading><form wire:submit="{{ $editingControlId ? 'saveControl' : 'addControl' }}" class="mt-4 space-y-3"><flux:select wire:model="controlType" label="Control">@foreach(['heading','text','input','textarea','select','checkbox','button','table'] as $type)<flux:select.option value="{{ $type }}">{{ ucfirst($type) }}</flux:select.option>@endforeach</flux:select><flux:input wire:model="controlLabel" label="Label" placeholder="Customer name" /><flux:select wire:model="controlFieldId" label="Bound field"><flux:select.option value="">None</flux:select.option>@foreach($iteration->models as $model)@foreach($model->fields as $field)<flux:select.option value="{{ $field->id }}">{{ $model->name }} · {{ $field->name }}</flux:select.option>@endforeach @endforeach</flux:select><flux:select wire:model="controlWidth" label="Width"><flux:select.option value="full">Full</flux:select.option><flux:select.option value="half">Half</flux:select.option><flux:select.option value="third">One third</flux:select.option><flux:select.option value="two-thirds">Two thirds</flux:select.option></flux:select><flux:textarea wire:model="controlOptions" label="Select options" description="One per line: value:Label" rows="4" /><div class="flex gap-2"><flux:button type="submit" class="flex-1" :disabled="!$selectedPageId">{{ $editingControlId ? 'Save control' : 'Add to canvas' }}</flux:button>@if($editingControlId)<flux:button wire:click="cancelControlEdit" type="button" variant="ghost">Cancel</flux:button>@endif</div></form></aside>
        </div>
    @elseif ($mode === 'preview')
        <div class="grid gap-6 lg:grid-cols-[1fr_22rem]">
            <div class="rounded-xl border border-zinc-200 bg-white p-8 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-start justify-between gap-4"><div><flux:heading size="lg">Debug and preview</flux:heading><flux:text class="mt-2">{{ $iteration->models->count() }} models, {{ $iteration->pages->count() }} pages and {{ $iteration->pages->sum(fn($page) => $page->controls->count()) }} controls.</flux:text></div><flux:button wire:click="runValidation" variant="primary" icon="play">Run validation</flux:button></div>
                @if($validationRun)<div class="mt-7 space-y-3">@foreach($validationRun->checks ?? [] as $check)<flux:callout :variant="$check['level'] === 'success' ? 'success' : 'danger'" :icon="$check['level'] === 'success' ? 'check-circle' : 'x-circle'" :heading="$check['label']"><flux:callout.text>{{ $check['message'] }}</flux:callout.text></flux:callout>@endforeach</div>@else<div class="mt-8 rounded-lg border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700"><flux:text>No validation run yet.</flux:text></div>@endif
                @if($previewMessage)<flux:callout class="mt-6" :variant="$previewRun?->status === 'failed' ? 'danger' : 'success'" heading="Debugger"><flux:callout.text>{{ $previewMessage }}</flux:callout.text></flux:callout>@endif
                <div class="mt-6 flex flex-wrap gap-2"><flux:button wire:click="startPreview" icon="play" :disabled="$assemblyRun?->status !== 'passed' || $previewRun?->status === 'running'">Launch debugger</flux:button><flux:button wire:click="openPreview" icon="arrow-top-right-on-square" :disabled="$previewRun?->status !== 'running'">Open application</flux:button><flux:button wire:click="stopPreview" variant="danger" icon="stop" :disabled="$previewRun?->status !== 'running'">Stop</flux:button></div>
            </div>
            <aside class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900"><flux:heading size="sm">Pipeline</flux:heading><div class="mt-5 space-y-4 text-sm"><div class="flex justify-between"><span>Schema validation</span><flux:badge :color="$iteration->status === 'validated' || $iteration->status === 'generated' ? 'green' : ($validationRun?->status === 'failed' ? 'red' : 'zinc')">{{ $iteration->status === 'validated' || $iteration->status === 'generated' ? 'passed' : ($validationRun?->status ?? 'Pending') }}</flux:badge></div><div class="flex justify-between"><span>Code generation</span><flux:badge :color="$iteration->status === 'generated' ? 'green' : 'zinc'">{{ $iteration->status }}</flux:badge></div><div class="flex justify-between"><span>Runtime tests</span><flux:badge :color="$assemblyRun?->status === 'passed' ? 'green' : ($assemblyRun?->status === 'failed' ? 'red' : 'zinc')">{{ $assemblyRun?->status ?? 'Pending' }}</flux:badge></div><div class="flex justify-between"><span>Debugger</span><flux:badge :color="$previewRun?->status === 'running' ? 'green' : ($previewRun?->status === 'failed' ? 'red' : 'zinc')">{{ $previewRun?->status ?? 'Pending' }}</flux:badge></div></div><flux:button wire:click="generate" class="mt-6 w-full" :disabled="$iteration->status !== 'validated'">Generate review bundle</flux:button></aside>
        </div>
    @else
        <div class="grid gap-6 lg:grid-cols-[1fr_22rem]">
            <div class="space-y-6">
                <div class="rounded-xl border border-zinc-200 bg-white p-8 dark:border-zinc-700 dark:bg-zinc-900"><flux:heading size="lg">Build runnable application</flux:heading><flux:text class="mt-2">Create a clean Laravel application at <code>{{ $project->output_path ?: 'an output folder selected in project setup' }}</code>, apply this iteration, install approved packages, build assets, migrate, and run its tests.</flux:text>@if($assemblyMessage)<flux:callout class="mt-6" :variant="$assemblyRun?->status === 'passed' ? 'success' : 'danger'" :icon="$assemblyRun?->status === 'passed' ? 'check-circle' : 'x-circle'" heading="Project assembly"><flux:callout.text>{{ $assemblyMessage }}</flux:callout.text></flux:callout>@endif<flux:button wire:click="assembleProject" wire:confirm="Create the application in the configured output folder? Existing folders are never overwritten." class="mt-6" variant="primary" icon="wrench-screwdriver" :disabled="$validationRun?->status !== 'passed' || $iteration->status !== 'generated' || !$project->output_path">Build and test application</flux:button></div>
                <div class="rounded-xl border border-zinc-200 bg-white p-8 dark:border-zinc-700 dark:bg-zinc-900"><flux:heading size="lg">Package project</flux:heading><flux:text class="mt-2">Create an immutable design-review ZIP or package the complete tested Laravel application. Application packages omit <code>.env</code>, Git metadata, and <code>node_modules</code>.</flux:text>@if($packagePath)<flux:callout class="mt-6" variant="success" icon="archive-box" heading="Review ZIP ready"><flux:callout.text>{{ $packagePath }}</flux:callout.text></flux:callout>@endif @if($applicationPackagePath)<flux:callout class="mt-3" variant="success" icon="archive-box" heading="Application ZIP ready"><flux:callout.text>{{ $applicationPackagePath }}</flux:callout.text></flux:callout>@endif<div class="mt-6 flex flex-wrap gap-2"><flux:button wire:click="packageIteration" icon="archive-box" :disabled="$validationRun?->status !== 'passed' || $iteration->status !== 'generated'">Package review bundle</flux:button><flux:button wire:click="packageApplication" variant="primary" icon="archive-box" :disabled="$assemblyRun?->status !== 'passed'">Package complete application</flux:button></div></div>
                <div class="rounded-xl border border-zinc-200 bg-white p-8 dark:border-zinc-700 dark:bg-zinc-900"><flux:heading size="lg">Publish to GitHub</flux:heading><flux:text class="mt-2">Commit the assembled application and push it using your authenticated GitHub CLI. New repositories are private by default.</flux:text><flux:input wire:model="githubRepository" class="mt-5" label="Repository" placeholder="owner/project-name" />@if($githubMessage)<flux:callout class="mt-5" :variant="$githubRun?->status === 'passed' ? 'success' : 'danger'" heading="GitHub delivery"><flux:callout.text>{{ $githubMessage }}</flux:callout.text></flux:callout>@endif<flux:button wire:click="publishToGitHub" wire:confirm="Commit and push this assembled application to GitHub?" class="mt-5" icon="cloud-arrow-up" :disabled="$assemblyRun?->status !== 'passed'">Commit and push</flux:button></div>
                <div class="rounded-xl border border-zinc-200 bg-white p-8 dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:heading size="lg">Packages and plugins</flux:heading><flux:text class="mt-2">Declare Composer or npm dependencies. Nothing is installed until you explicitly approve it.</flux:text>
                    <form wire:submit="addPlugin" class="mt-5 grid gap-3 md:grid-cols-[8rem_1fr_8rem_auto]"><flux:select wire:model="pluginType" label="Type"><flux:select.option value="composer">Composer</flux:select.option><flux:select.option value="npm">npm</flux:select.option></flux:select><flux:input wire:model="pluginPackage" label="Package" placeholder="vendor/package" /><flux:input wire:model="pluginConstraint" label="Version" placeholder="^1.0" /><flux:button type="submit" class="self-end">Add</flux:button></form>
                    <div class="mt-5 space-y-2">@forelse($iteration->plugins as $plugin)<div class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-zinc-50 p-3 text-sm dark:bg-zinc-800"><div><code>{{ $plugin->package }}:{{ $plugin->constraint }}</code><div class="mt-1 text-xs text-zinc-500">{{ strtoupper($plugin->type) }}</div></div><div class="flex gap-2"><flux:button wire:click="togglePluginApproval({{ $plugin->id }})" size="sm" :variant="$plugin->approved ? 'primary' : 'ghost'">{{ $plugin->approved ? 'Approved' : 'Approve' }}</flux:button><flux:button wire:click="removePlugin({{ $plugin->id }})" wire:confirm="Remove this dependency?" size="sm" variant="danger" icon="trash" /></div></div>@empty<flux:text>No additional dependencies.</flux:text>@endforelse</div>
                </div>
            </div>
            <aside class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900"><flux:heading size="sm">Packages</flux:heading><div class="mt-4 space-y-3">@forelse($iteration->packages as $package)<div class="rounded-lg bg-zinc-50 p-3 text-sm dark:bg-zinc-800"><div class="flex justify-between"><span>{{ strtoupper($package->format) }}</span><span>{{ number_format($package->bytes / 1024, 1) }} KB</span></div><code class="mt-2 block truncate text-xs">{{ $package->checksum }}</code></div>@empty<flux:text>No packages created.</flux:text>@endforelse</div></aside>
        </div>
    @endif
</div>
