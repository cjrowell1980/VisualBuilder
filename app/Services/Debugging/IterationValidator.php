<?php

namespace App\Services\Debugging;

use App\Models\BuildIteration;
use App\Models\BuildRun;
use App\Models\ModelDefinition;

class IterationValidator
{
    public function run(BuildIteration $iteration): BuildRun
    {
        $run = $iteration->runs()->create(['type' => 'validation', 'status' => 'running', 'started_at' => now()]);
        $iteration->load('models.fields', 'models.relationships.target', 'pages.controls.field.modelDefinition', 'pages.modelDefinition');

        $checks = [];
        $checks[] = $this->check(
            $iteration->models->isNotEmpty(),
            'Data model',
            $iteration->models->isNotEmpty() ? $iteration->models->count().' model(s) defined.' : 'Add at least one model.'
        );
        $checks[] = $this->check(
            $iteration->models->pluck('table_name')->unique()->count() === $iteration->models->count(),
            'Database tables',
            'Every model must use a unique table name.'
        );
        $checks[] = $this->check(
            $this->databaseGraphIsAcyclic($iteration),
            'Migration dependencies',
            'Database relationships must not contain a circular table dependency.'
        );

        foreach ($iteration->models as $model) {
            $checks[] = $this->check(
                $model->fields->isNotEmpty(),
                "Model: {$model->name}",
                $model->fields->isNotEmpty() ? $model->fields->count().' field(s) defined.' : 'The model has no fields.'
            );
            $validRelationships = $model->relationships->every(fn ($relationship): bool => $relationship->target->build_iteration_id === $iteration->id);
            $checks[] = $this->check(
                $validRelationships,
                "Relationships: {$model->name}",
                $validRelationships ? 'Relationship targets belong to this iteration.' : 'A relationship targets a model from another iteration.'
            );
        }

        $pagesRequired = $iteration->project->template !== 'api';
        $pagesValid = ! $pagesRequired || $iteration->pages->isNotEmpty();
        $pageMessage = $iteration->pages->isNotEmpty()
            ? $iteration->pages->count().' page(s) defined.'
            : ($pagesRequired ? 'Add at least one page.' : 'Pages are optional for an API-only project.');
        $checks[] = $this->check($pagesValid, 'Pages', $pageMessage);

        foreach ($iteration->pages as $page) {
            $validBindings = $page->controls->every(function ($control) use ($page): bool {
                return $control->model_field_id === null
                    || $page->model_definition_id === null
                    || $control->field->model_definition_id === $page->model_definition_id;
            });
            $checks[] = $this->check(
                $page->controls->isNotEmpty() && $validBindings,
                "Page: {$page->name}",
                ! $validBindings ? 'A control is bound to a field from a different model.' : ($page->controls->isNotEmpty() ? $page->controls->count().' control(s) valid.' : 'The page has no controls.')
            );
        }

        $failed = collect($checks)->contains(fn (array $check): bool => $check['level'] === 'error');
        $status = $failed ? 'failed' : 'passed';
        $run->update([
            'status' => $status,
            'checks' => $checks,
            'output' => $failed ? 'Iteration validation failed.' : 'Iteration validation passed.',
            'finished_at' => now(),
        ]);
        $iteration->update(['status' => $failed ? 'draft' : 'validated', 'generated_at' => null]);

        return $run->fresh();
    }

    /** @return array{level: string, label: string, message: string} */
    private function check(bool $passes, string $label, string $message): array
    {
        return ['level' => $passes ? 'success' : 'error', 'label' => $label, 'message' => $message];
    }

    private function databaseGraphIsAcyclic(BuildIteration $iteration): bool
    {
        $remaining = $iteration->models->keyBy('id');
        while ($remaining->isNotEmpty()) {
            $next = $remaining->first(fn (ModelDefinition $model): bool => collect($this->databaseDependencyIds($model, $iteration))
                ->every(fn (int $dependencyId): bool => ! $remaining->has($dependencyId)));
            if (! $next) {
                return false;
            }
            $remaining->forget($next->id);
        }

        return true;
    }

    /** @return list<int> */
    private function databaseDependencyIds(ModelDefinition $model, BuildIteration $iteration): array
    {
        $dependencies = [];
        foreach ($model->relationships->where('type', 'belongsTo') as $relationship) {
            $dependencies[(int) $relationship->target_model_id] = (int) $relationship->target_model_id;
        }

        foreach ($iteration->models as $source) {
            if ($source->relationships->whereIn('type', ['hasOne', 'hasMany'])->where('target_model_id', $model->id)->isNotEmpty()) {
                $dependencies[(int) $source->id] = (int) $source->id;
            }
        }

        unset($dependencies[(int) $model->id]);

        return array_values($dependencies);
    }
}
