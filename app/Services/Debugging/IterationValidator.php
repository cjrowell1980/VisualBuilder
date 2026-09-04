<?php

namespace App\Services\Debugging;

use App\Models\BuildIteration;
use App\Models\BuildRun;

class IterationValidator
{
    public function run(BuildIteration $iteration): BuildRun
    {
        $run = $iteration->runs()->create(['type' => 'validation', 'status' => 'running', 'started_at' => now()]);
        $iteration->load('models.fields', 'models.relationships', 'pages.controls.field.modelDefinition', 'pages.modelDefinition');

        $checks = [];
        $checks[] = $this->check(
            $iteration->models->isNotEmpty(),
            'Data model',
            $iteration->models->isNotEmpty() ? $iteration->models->count().' model(s) defined.' : 'Add at least one model.'
        );

        foreach ($iteration->models as $model) {
            $checks[] = $this->check(
                $model->fields->isNotEmpty(),
                "Model: {$model->name}",
                $model->fields->isNotEmpty() ? $model->fields->count().' field(s) defined.' : 'The model has no fields.'
            );
        }

        $checks[] = $this->check(
            $iteration->pages->isNotEmpty(),
            'Pages',
            $iteration->pages->isNotEmpty() ? $iteration->pages->count().' page(s) defined.' : 'Add at least one page.'
        );

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

        return $run->fresh();
    }

    /** @return array{level: string, label: string, message: string} */
    private function check(bool $passes, string $label, string $message): array
    {
        return ['level' => $passes ? 'success' : 'error', 'label' => $label, 'message' => $message];
    }
}
