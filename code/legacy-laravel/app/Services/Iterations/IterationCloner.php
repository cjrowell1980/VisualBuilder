<?php

namespace App\Services\Iterations;

use App\Models\BuildIteration;
use Illuminate\Support\Facades\DB;

class IterationCloner
{
    public function clone(BuildIteration $source, string $name): BuildIteration
    {
        return DB::transaction(function () use ($source, $name): BuildIteration {
            $source->load('models.fields', 'models.relationships', 'pages.controls', 'plugins');
            $next = $source->project->iterations()->create([
                'number' => $source->project->iterations()->max('number') + 1,
                'name' => $name,
                'status' => 'draft',
                'configuration' => $source->configuration,
            ]);

            $modelMap = [];
            $fieldMap = [];
            foreach ($source->models as $model) {
                $copy = $next->models()->create($model->only(['name', 'table_name', 'soft_deletes', 'timestamps']));
                $modelMap[$model->id] = $copy;
                foreach ($model->fields as $field) {
                    $fieldCopy = $copy->fields()->create($field->only([
                        'name', 'label', 'type', 'nullable', 'indexed', 'unique',
                        'default_value', 'validation_rules', 'position',
                    ]));
                    $fieldMap[$field->id] = $fieldCopy->id;
                }
            }

            foreach ($source->models as $model) {
                foreach ($model->relationships as $relationship) {
                    $modelMap[$model->id]->relationships()->create([
                        ...$relationship->only(['name', 'type', 'foreign_key']),
                        'target_model_id' => $modelMap[$relationship->target_model_id]->id,
                    ]);
                }
            }

            foreach ($source->pages as $page) {
                $pageCopy = $next->pages()->create([
                    ...$page->only(['name', 'slug', 'page_type', 'layout', 'position']),
                    'model_definition_id' => $page->model_definition_id ? $modelMap[$page->model_definition_id]->id : null,
                ]);
                foreach ($page->controls as $control) {
                    $pageCopy->controls()->create([
                        ...$control->only(['control_type', 'label', 'width', 'configuration', 'position']),
                        'model_field_id' => $control->model_field_id ? $fieldMap[$control->model_field_id] : null,
                    ]);
                }
            }

            foreach ($source->plugins as $plugin) {
                $next->plugins()->create($plugin->only(['package', 'constraint', 'type', 'approved']));
            }

            return $next;
        });
    }
}
