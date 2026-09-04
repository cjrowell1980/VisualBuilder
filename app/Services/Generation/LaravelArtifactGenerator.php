<?php

namespace App\Services\Generation;

use App\Models\BuildIteration;
use App\Models\ControlDefinition;
use App\Models\ModelDefinition;
use App\Models\PageDefinition;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LaravelArtifactGenerator
{
    /** @return array{path: string, files: list<string>} */
    public function generate(BuildIteration $iteration): array
    {
        $iteration->load('project', 'models.fields', 'models.relationships.target', 'pages.controls.field', 'pages.modelDefinition', 'plugins');
        $root = "generated/{$iteration->project->slug}/iteration-{$iteration->number}";
        Storage::disk('local')->deleteDirectory($root);

        $files = [];
        foreach ($iteration->models as $model) {
            $files[] = $this->write($root, "app/Models/{$model->name}.php", $this->model($model));
            $files[] = $this->write($root, 'database/migrations/'.now()->format('Y_m_d_His').'_create_'.$model->table_name.'_table.php', $this->migration($model));
        }

        foreach ($iteration->pages as $page) {
            $files[] = $this->write($root, "resources/views/pages/{$page->slug}.blade.php", $this->page($page));
        }
        if ($iteration->pages->isNotEmpty()) {
            $files[] = $this->write($root, 'routes/generated.php', $this->routes($iteration));
        }

        $manifest = [
            'project' => $iteration->project->only(['name', 'slug', 'template', 'database_driver', 'docker_enabled']),
            'iteration' => $iteration->only(['number', 'name']),
            'stack' => ['laravel' => '^13.0', 'livewire' => '^4.0', 'flux' => '^2.0', 'tailwindcss' => '^4.0'],
            'plugins' => $iteration->plugins->map->only(['type', 'package', 'constraint', 'approved'])->values(),
            'models' => $iteration->models->map(fn (ModelDefinition $model) => [
                ...$model->only(['name', 'table_name', 'soft_deletes', 'timestamps']),
                'fields' => $model->fields->map->only(['name', 'label', 'type', 'nullable', 'indexed', 'unique', 'validation_rules'])->values(),
            ])->values(),
            'pages' => $iteration->pages->map(fn (PageDefinition $page) => [
                ...$page->only(['name', 'slug', 'page_type', 'layout']),
                'controls' => $page->controls->map->only(['control_type', 'label', 'width', 'configuration'])->values(),
            ])->values(),
            'files' => $files,
            'generated_at' => now()->toIso8601String(),
        ];

        $files[] = $this->write($root, 'visual-builder.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        $iteration->update(['status' => 'generated', 'generated_at' => now()]);

        return ['path' => Storage::disk('local')->path($root), 'files' => $files];
    }

    private function write(string $root, string $path, string $contents): string
    {
        Storage::disk('local')->put("{$root}/{$path}", $contents);

        return $path;
    }

    private function model(ModelDefinition $model): string
    {
        $fillable = $model->fields->pluck('name')->map(fn (string $name) => "'{$name}'")->implode(', ');
        $softDeletesImport = $model->soft_deletes ? "use Illuminate\\Database\\Eloquent\\SoftDeletes;\n" : '';
        $softDeletesUse = $model->soft_deletes ? "\n    use SoftDeletes;\n" : '';

        return <<<PHP
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
{$softDeletesImport}
class {$model->name} extends Model
{{$softDeletesUse}
    protected \$fillable = [{$fillable}];
}
PHP;
    }

    private function migration(ModelDefinition $model): string
    {
        $columns = $model->fields->map(function ($field): string {
            $method = match ($field->type) {
                'text' => 'text', 'integer' => 'integer', 'boolean' => 'boolean',
                'date' => 'date', 'datetime' => 'dateTime', 'decimal' => 'decimal',
                'json' => 'json', default => 'string',
            };
            $chain = $field->nullable ? '->nullable()' : '';
            $chain .= $field->indexed ? '->index()' : '';

            return "            \$table->{$method}('{$field->name}'){$chain};";
        })->implode("\n");
        $timestamps = $model->timestamps ? "\n            \$table->timestamps();" : '';
        $softDeletes = $model->soft_deletes ? "\n            \$table->softDeletes();" : '';
        $table = $model->table_name;

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$table}', function (Blueprint \$table) {
            \$table->id();
{$columns}{$timestamps}{$softDeletes}
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$table}');
    }
};
PHP;
    }

    private function page(PageDefinition $page): string
    {
        $controls = $page->controls->map(fn (ControlDefinition $control) => $this->control($control))->implode("\n\n");
        $title = e($page->name);

        return <<<BLADE
<?php

use Livewire\Component;

new class extends Component {};
?>

<div class="mx-auto w-full max-w-7xl space-y-6 p-6 lg:p-10">
    <flux:heading size="xl">{$title}</flux:heading>
{$controls}
</div>
BLADE;
    }

    private function control(ControlDefinition $control): string
    {
        $label = e($control->label ?: Str::headline($control->control_type));
        $field = $control->model_field_id === null
            ? Str::snake($control->label ?: 'value')
            : $control->field->name;

        return match ($control->control_type) {
            'heading' => "    <flux:heading>{$label}</flux:heading>",
            'text' => "    <flux:text>{$label}</flux:text>",
            'textarea' => "    <flux:textarea wire:model=\"{$field}\" label=\"{$label}\" />",
            'select' => "    <flux:select wire:model=\"{$field}\" label=\"{$label}\"></flux:select>",
            'checkbox' => "    <flux:checkbox wire:model=\"{$field}\" label=\"{$label}\" />",
            'button' => "    <flux:button variant=\"primary\">{$label}</flux:button>",
            'table' => "    <div class=\"overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700\"><div class=\"p-4\">{$label}</div></div>",
            default => "    <flux:input wire:model=\"{$field}\" label=\"{$label}\" />",
        };
    }

    private function routes(BuildIteration $iteration): string
    {
        $routes = $iteration->pages->map(function (PageDefinition $page): string {
            $name = Str::of($page->slug)->replace('/', '.')->toString();

            return "Route::livewire('/{$page->slug}', 'pages::{$page->slug}')->name('{$name}');";
        })->implode("\n");

        return <<<PHP
<?php

use Illuminate\Support\Facades\Route;

{$routes}
PHP;
    }
}
