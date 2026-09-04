<?php

namespace App\Services\Generation;

use App\Models\BuildIteration;
use App\Models\ControlDefinition;
use App\Models\ModelDefinition;
use App\Models\ModelField;
use App\Models\ModelRelationship;
use App\Models\PageDefinition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LaravelArtifactGenerator
{
    /** @return array{path: string, files: list<string>} */
    public function generate(BuildIteration $iteration): array
    {
        if ($iteration->status !== 'validated') {
            throw new \RuntimeException('Run validation after the latest design change before generating code.');
        }
        $iteration->load('project', 'models.fields', 'models.relationships.target', 'pages.controls.field', 'pages.modelDefinition', 'plugins');
        $root = "generated/{$iteration->project->slug}/iteration-{$iteration->number}";
        Storage::disk('local')->deleteDirectory($root);

        $files = [];
        $migrationTime = now();
        $migrationSequence = 0;
        $generatedPivots = [];
        foreach ($iteration->models as $model) {
            $files[] = $this->write($root, "app/Models/{$model->name}.php", $this->model($model));
            if ($iteration->project->template !== 'application') {
                $files[] = $this->write($root, "app/Http/Controllers/Api/{$model->name}Controller.php", $this->apiController($model));
            }
        }
        foreach ($this->migrationOrder($iteration) as $model) {
            $timestamp = $migrationTime->addSeconds($migrationSequence++)->format('Y_m_d_His');
            $files[] = $this->write($root, 'database/migrations/'.$timestamp.'_create_'.$model->table_name.'_table.php', $this->migration($model));
        }
        foreach ($iteration->models as $model) {
            foreach ($model->relationships->where('type', 'belongsToMany') as $relationship) {
                $pivot = $this->pivotName($model, $relationship->target);
                if (! in_array($pivot, $generatedPivots, true)) {
                    $timestamp = $migrationTime->addSeconds($migrationSequence++)->format('Y_m_d_His');
                    $files[] = $this->write($root, 'database/migrations/'.$timestamp.'_create_'.$pivot.'_table.php', $this->pivotMigration($model, $relationship, $pivot));
                    $generatedPivots[] = $pivot;
                }
            }
        }
        if ($iteration->models->isNotEmpty()) {
            $files[] = $this->write($root, 'tests/Feature/GeneratedSchemaTest.php', $this->schemaTest($iteration));
        }
        if ($iteration->pages->isNotEmpty() || ($iteration->project->template !== 'application' && $iteration->models->isNotEmpty())) {
            $files[] = $this->write($root, 'tests/Feature/GeneratedRoutesTest.php', $this->routesTest($iteration));
        }

        foreach ($iteration->pages as $page) {
            $files[] = $this->write($root, "resources/views/pages/{$page->slug}.blade.php", $this->page($page));
        }
        if ($iteration->pages->isNotEmpty()) {
            $files[] = $this->write($root, 'routes/generated.php', $this->routes($iteration));
        }
        if ($iteration->project->template !== 'application' && $iteration->models->isNotEmpty()) {
            $files[] = $this->write($root, 'routes/generated-api.php', $this->apiRoutes($iteration));
        }
        if ($iteration->project->docker_enabled) {
            $files[] = $this->write($root, 'Dockerfile', $this->dockerfile($iteration));
            $files[] = $this->write($root, 'compose.yaml', $this->compose($iteration));
            $files[] = $this->write($root, 'docker/nginx.conf', $this->nginx());
            $files[] = $this->write($root, 'docker/supervisord.conf', $this->supervisor());
            $files[] = $this->write($root, '.github/workflows/publish-image.yml', $this->publishImageWorkflow());
        }
        $files[] = $this->write($root, '.github/workflows/tests.yml', $this->testWorkflow());

        $manifest = [
            'project' => $iteration->project->only(['name', 'slug', 'template', 'database_driver', 'docker_enabled']),
            'iteration' => $iteration->only(['number', 'name']),
            'stack' => ['laravel' => '^13.0', 'livewire' => '^4.0', 'flux' => '^2.0', 'tailwindcss' => '^4.0'],
            'plugins' => $iteration->plugins->map->only(['type', 'package', 'constraint', 'approved'])->values(),
            'models' => $iteration->models->map(fn (ModelDefinition $model) => [
                ...$model->only(['name', 'table_name', 'soft_deletes', 'timestamps']),
                'fields' => $model->fields->map->only(['name', 'label', 'type', 'nullable', 'indexed', 'unique', 'default_value', 'validation_rules'])->values(),
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

    /** @return list<ModelDefinition> */
    private function migrationOrder(BuildIteration $iteration): array
    {
        $remaining = $iteration->models->keyBy('id');
        $ordered = [];
        while ($remaining->isNotEmpty()) {
            $next = $remaining->first(function (ModelDefinition $model) use ($remaining): bool {
                return $model->relationships
                    ->where('type', 'belongsTo')
                    ->every(fn (ModelRelationship $relationship): bool => $relationship->target_model_id === $model->id
                        || ! $remaining->has($relationship->target_model_id));
            });
            if (! $next) {
                throw new \RuntimeException('Circular belongsTo relationships must be redesigned before migrations can be generated.');
            }
            $ordered[] = $next;
            $remaining->forget($next->id);
        }

        return $ordered;
    }

    private function model(ModelDefinition $model): string
    {
        $fillable = $model->fields->pluck('name')->map(fn (string $name) => "'{$name}'")->implode(', ');
        $softDeletesImport = $model->soft_deletes ? "use Illuminate\\Database\\Eloquent\\SoftDeletes;\n" : '';
        $softDeletesUse = $model->soft_deletes ? "\n    use SoftDeletes;\n" : '';
        $timestampsProperty = $model->getAttribute('timestamps') ? '' : "\n    public \$timestamps = false;\n";
        $relationTypes = $model->relationships->pluck('type')->unique()->map(fn (string $type): string => match ($type) {
            'belongsTo' => 'BelongsTo',
            'hasOne' => 'HasOne',
            'hasMany' => 'HasMany',
            'belongsToMany' => 'BelongsToMany',
            default => throw new \RuntimeException("Unsupported relationship type: {$type}"),
        });
        $relationImports = $relationTypes->map(fn (string $type): string => "use Illuminate\\Database\\Eloquent\\Relations\\{$type};")->implode("\n");
        $relationImports = $relationImports === '' ? '' : $relationImports."\n";
        $relations = $model->relationships->map(fn (ModelRelationship $relationship): string => $this->relationship($relationship))->implode("\n\n");
        $relations = $relations === '' ? '' : "\n\n{$relations}";

        return <<<PHP
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
{$relationImports}{$softDeletesImport}
class {$model->name} extends Model
{{$softDeletesUse}
    protected \$fillable = [{$fillable}];
{$timestampsProperty}{$relations}
}
PHP;
    }

    private function relationship(ModelRelationship $relationship): string
    {
        $returnType = match ($relationship->type) {
            'belongsTo' => 'BelongsTo',
            'hasOne' => 'HasOne',
            'hasMany' => 'HasMany',
            'belongsToMany' => 'BelongsToMany',
            default => throw new \RuntimeException("Unsupported relationship type: {$relationship->type}"),
        };
        $arguments = "{$relationship->target->name}::class";
        if ($relationship->type === 'belongsTo' && $relationship->foreign_key) {
            $arguments .= ", '{$relationship->foreign_key}'";
        } elseif ($relationship->type === 'belongsToMany') {
            $sourceKey = Str::snake(Str::singular($relationship->source->name)).'_id';
            $targetKey = $relationship->source_model_id === $relationship->target_model_id
                ? Str::snake(Str::singular($relationship->name)).'_id'
                : Str::snake(Str::singular($relationship->target->name)).'_id';
            $arguments .= ", '{$this->pivotName($relationship->source, $relationship->target)}', '{$sourceKey}', '{$targetKey}'";
        }

        return <<<PHP
    public function {$relationship->name}(): {$returnType}
    {
        return \$this->{$relationship->type}({$arguments});
    }
PHP;
    }

    private function pivotName(ModelDefinition $source, ModelDefinition $target): string
    {
        return collect([
            Str::snake(Str::singular($source->name)),
            Str::snake(Str::singular($target->name)),
        ])->sort()->implode('_');
    }

    private function pivotMigration(ModelDefinition $source, ModelRelationship $relationship, string $pivot): string
    {
        $target = $relationship->target;
        $sourceKey = Str::snake(Str::singular($source->name)).'_id';
        $targetKey = $source->id === $target->id
            ? Str::snake(Str::singular($relationship->name)).'_id'
            : Str::snake(Str::singular($target->name)).'_id';

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$pivot}', function (Blueprint \$table) {
            \$table->foreignId('{$sourceKey}')->constrained('{$source->table_name}')->cascadeOnDelete();
            \$table->foreignId('{$targetKey}')->constrained('{$target->table_name}')->cascadeOnDelete();
            \$table->unique(['{$sourceKey}', '{$targetKey}']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$pivot}');
    }
};
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
            $chain .= $this->migrationDefault($field);
            $chain .= $field->indexed ? '->index()' : '';
            $chain .= $field->unique ? '->unique()' : '';

            return "            \$table->{$method}('{$field->name}'){$chain};";
        })->implode("\n");
        $foreignKeys = $model->relationships
            ->where('type', 'belongsTo')
            ->map(fn (ModelRelationship $relationship): string => "            \$table->foreignId('{$relationship->foreign_key}')->constrained('{$relationship->target->table_name}');")
            ->implode("\n");
        $foreignKeys = $foreignKeys === '' ? '' : $foreignKeys."\n";
        $timestamps = $model->getAttribute('timestamps') ? "\n            \$table->timestamps();" : '';
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
{$foreignKeys}{$columns}{$timestamps}{$softDeletes}
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$table}');
    }
};
PHP;
    }

    private function migrationDefault(ModelField $field): string
    {
        if ($field->default_value === null || $field->default_value === '') {
            return '';
        }
        $value = match ($field->type) {
            'integer', 'decimal' => $field->default_value,
            'boolean' => in_array(strtolower($field->default_value), ['true', '1'], true) ? 'true' : 'false',
            default => var_export($field->default_value, true),
        };

        return "->default({$value})";
    }

    private function page(PageDefinition $page): string
    {
        $model = $page->modelDefinition;
        $boundFields = $page->controls
            ->map(fn (ControlDefinition $control): ?ModelField => $control->field)
            ->filter()
            ->unique('id')
            ->values();
        $properties = $boundFields->map(fn ($field): string => "    public mixed \${$field->name} = null;")->implode("\n");
        $properties = $properties === '' ? '' : "\n{$properties}\n";
        $behaviour = '';
        $hasSave = false;
        $modelImport = '';
        if ($model) {
            $modelImport = "use App\\Models\\{$model->name};\n";
        }
        if ($model && $page->page_type === 'index') {
            $behaviour = <<<PHP

    public function with(): array
    {
        return ['records' => {$model->name}::query()->latest()->get()];
    }
PHP;
        } elseif ($model && in_array($page->page_type, ['edit', 'show'], true)) {
            $assignments = $boundFields->map(fn ($field): string => "        \$this->{$field->name} = \$model->{$field->name};")->implode("\n");
            $behaviour = <<<PHP

    public int \$recordId;

    public function mount(int \$record): void
    {
        \$model = {$model->name}::query()->findOrFail(\$record);
        \$this->recordId = \$model->id;
{$assignments}
    }
PHP;
        }
        if ($model && $boundFields->isNotEmpty() && ! in_array($page->page_type, ['index', 'show'], true)) {
            $ignoreExpression = $page->page_type === 'edit' ? '$this->recordId' : null;
            $rulesCode = $this->validationRulesCode($boundFields, $model, $ignoreExpression);
            if ($boundFields->contains(fn (ModelField $field): bool => $this->fieldHasUniqueRule($field))) {
                $modelImport .= "use Illuminate\\Validation\\Rule;\n";
            }
            $fieldNames = $boundFields->pluck('name')->map(fn (string $name): string => "'{$name}'")->implode(', ');
            $persistence = $page->page_type === 'edit'
                ? "{$model->name}::query()->findOrFail(\$this->recordId)->update(\$validated);"
                : "{$model->name}::query()->create(\$validated);";
            $reset = $page->page_type === 'edit' ? '' : "\n        \$this->reset({$fieldNames});";
            $behaviour .= <<<PHP

    public function save(): void
    {
        \$validated = \$this->validate({$rulesCode});
        {$persistence}{$reset}
        session()->flash('status', '{$model->name} saved.');
    }
PHP;
            $hasSave = true;
        }
        $controls = $page->controls->map(fn (ControlDefinition $control) => $this->control($control, $page, $hasSave))->implode("\n\n");
        $title = e($page->name);

        return <<<BLADE
<?php

{$modelImport}use Livewire\Component;

new class extends Component
{{$properties}{$behaviour}
};
?>

<div class="mx-auto w-full max-w-7xl space-y-6 p-6 lg:p-10">
    <flux:heading size="xl">{$title}</flux:heading>
    @if (session('status'))
        <flux:callout variant="success" heading="Saved">{{ session('status') }}</flux:callout>
    @endif
{$controls}
</div>
BLADE;
    }

    private function control(ControlDefinition $control, PageDefinition $page, bool $hasSave): string
    {
        $label = e($control->label ?: Str::headline($control->control_type));
        $field = $control->model_field_id === null
            ? Str::snake($control->label ?: 'value')
            : $control->field->name;

        $options = collect($control->configuration['options'] ?? [])->map(function (array $option): string {
            $value = e($option['value']);
            $optionLabel = e($option['label']);

            return "        <flux:select.option value=\"{$value}\">{$optionLabel}</flux:select.option>";
        })->implode("\n");
        $markup = match ($control->control_type) {
            'heading' => "    <flux:heading>{$label}</flux:heading>",
            'text' => "    <flux:text>{$label}</flux:text>",
            'textarea' => "    <flux:textarea wire:model=\"{$field}\" label=\"{$label}\" />",
            'select' => "    <flux:select wire:model=\"{$field}\" label=\"{$label}\">\n{$options}\n    </flux:select>",
            'checkbox' => "    <flux:checkbox wire:model=\"{$field}\" label=\"{$label}\" />",
            'button' => $hasSave
                ? "    <flux:button wire:click=\"save\" variant=\"primary\">{$label}</flux:button>"
                : "    <flux:button variant=\"primary\">{$label}</flux:button>",
            'table' => $page->page_type === 'index' && $page->modelDefinition
                ? $this->table($page)
                : "    <div class=\"overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700\"><div class=\"p-4\">{$label}</div></div>",
            default => "    <flux:input wire:model=\"{$field}\" label=\"{$label}\" />",
        };
        $width = match ($control->width) {
            'half' => 'max-w-2xl',
            'third' => 'max-w-lg',
            'two-thirds' => 'max-w-4xl',
            default => 'w-full',
        };

        return "    <div class=\"{$width}\">\n{$markup}\n    </div>";
    }

    private function table(PageDefinition $page): string
    {
        $headers = $page->modelDefinition->fields->map(fn ($field): string => '                    <th class="px-4 py-3 text-left">'.e($field->label ?: Str::headline($field->name)).'</th>')->implode("\n");
        $cells = $page->modelDefinition->fields->map(fn ($field): string => '                        <td class="px-4 py-3">{{ $record->'.$field->name.' }}</td>')->implode("\n");

        return <<<BLADE
    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead><tr>
{$headers}
            </tr></thead>
            <tbody>
                @forelse (\$records as \$record)
                    <tr class="border-t border-zinc-200 dark:border-zinc-700">
{$cells}
                    </tr>
                @empty
                    <tr><td class="px-4 py-6 text-center" colspan="99">No records found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
BLADE;
    }

    private function routes(BuildIteration $iteration): string
    {
        $routes = $iteration->pages->map(function (PageDefinition $page): string {
            $name = Str::of($page->slug)->replace('/', '.')->toString();
            $uri = in_array($page->page_type, ['edit', 'show'], true) ? "/{$page->slug}/{record}" : "/{$page->slug}";

            return "Route::livewire('{$uri}', 'pages::{$page->slug}')->name('{$name}');";
        })->implode("\n");

        return <<<PHP
<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
{$routes}
});
PHP;
    }

    private function apiController(ModelDefinition $model): string
    {
        $variable = Str::camel($model->name);
        $storeRulesCode = $this->validationRulesCode($model->fields, $model);
        $updateRulesCode = $this->validationRulesCode($model->fields, $model, '$'.$variable);
        $ruleImport = $model->fields->contains(fn (ModelField $field): bool => $this->fieldHasUniqueRule($field))
            ? "use Illuminate\\Validation\\Rule;\n"
            : '';

        return <<<PHP
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{$model->name};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
{$ruleImport}

class {$model->name}Controller extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json({$model->name}::query()->latest()->paginate());
    }

    public function store(Request \$request): JsonResponse
    {
        \${$variable} = {$model->name}::query()->create(\$request->validate({$storeRulesCode}));

        return response()->json(\${$variable}, 201);
    }

    public function show({$model->name} \${$variable}): JsonResponse
    {
        return response()->json(\${$variable});
    }

    public function update(Request \$request, {$model->name} \${$variable}): JsonResponse
    {
        \${$variable}->update(\$request->validate({$updateRulesCode}));

        return response()->json(\${$variable}->refresh());
    }

    public function destroy({$model->name} \${$variable}): JsonResponse
    {
        \${$variable}->delete();

        return response()->json(null, 204);
    }
}
PHP;
    }

    /** @param Collection<int, ModelField> $fields */
    private function validationRulesCode(Collection $fields, ModelDefinition $model, ?string $ignoreExpression = null): string
    {
        $lines = $fields->map(function (ModelField $field) use ($model, $ignoreExpression): string {
            $rules = $field->validation_rules ?: [$field->nullable ? 'nullable' : 'required'];
            $hasUniqueRule = $this->fieldHasUniqueRule($field);
            $expressions = collect($rules)
                ->reject(fn (string $rule): bool => str_starts_with($rule, 'unique'))
                ->map(fn (string $rule): string => var_export($rule, true));
            if ($hasUniqueRule) {
                $unique = "Rule::unique('{$model->table_name}', '{$field->name}')";
                if ($ignoreExpression !== null) {
                    $unique .= "->ignore({$ignoreExpression})";
                }
                $expressions->push($unique);
            }

            return "    '{$field->name}' => [".$expressions->implode(', ').'],';
        })->implode("\n");

        return "[\n{$lines}\n]";
    }

    private function fieldHasUniqueRule(ModelField $field): bool
    {
        if ($field->unique) {
            return true;
        }

        foreach ($field->validation_rules ?? [] as $rule) {
            if (str_starts_with($rule, 'unique')) {
                return true;
            }
        }

        return false;
    }

    private function schemaTest(BuildIteration $iteration): string
    {
        $methods = $iteration->models->map(function (ModelDefinition $model): string {
            $method = 'test_'.$model->table_name.'_schema_is_available';
            $columns = $model->fields->pluck('name')->prepend('id')->map(fn (string $column): string => "'{$column}'")->implode(', ');

            return <<<PHP
    public function {$method}(): void
    {
        \$this->assertTrue(Schema::hasTable('{$model->table_name}'));
        \$this->assertTrue(Schema::hasColumns('{$model->table_name}', [{$columns}]));
    }
PHP;
        })->implode("\n\n");

        return <<<PHP
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GeneratedSchemaTest extends TestCase
{
    use RefreshDatabase;

{$methods}
}
PHP;
    }

    private function routesTest(BuildIteration $iteration): string
    {
        $assertions = $iteration->pages->map(function (PageDefinition $page): string {
            $name = Str::of($page->slug)->replace('/', '.')->toString();

            return "        \$this->assertTrue(Route::has('{$name}'));";
        });
        if ($iteration->project->template !== 'application') {
            $assertions = $assertions->concat($iteration->models->map(
                fn (ModelDefinition $model): string => "        \$this->assertTrue(Route::has('{$model->table_name}.index'));"
            ));
        }
        $assertionCode = $assertions->implode("\n");

        return <<<PHP
<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class GeneratedRoutesTest extends TestCase
{
    public function test_generated_routes_are_registered(): void
    {
{$assertionCode}
    }
}
PHP;
    }

    private function apiRoutes(BuildIteration $iteration): string
    {
        $controllers = $iteration->models->map(fn (ModelDefinition $model): string => "use App\\Http\\Controllers\\Api\\{$model->name}Controller;")->implode("\n");
        $routes = $iteration->models->map(fn (ModelDefinition $model): string => "Route::apiResource('{$model->table_name}', {$model->name}Controller::class);")->implode("\n");

        return <<<PHP
<?php

{$controllers}
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
{$routes}
});
PHP;
    }

    private function dockerfile(BuildIteration $iteration): string
    {
        $packages = match ($iteration->project->database_driver) {
            'pgsql' => 'libpq-dev icu-dev nginx supervisor',
            'mysql' => 'icu-dev nginx supervisor',
            default => 'sqlite-dev icu-dev nginx supervisor',
        };
        $extensions = match ($iteration->project->database_driver) {
            'pgsql' => 'pdo_pgsql intl opcache',
            'mysql' => 'pdo_mysql intl opcache',
            default => 'pdo_sqlite intl opcache',
        };

        return <<<DOCKERFILE
FROM node:22-alpine AS assets
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.* ./
RUN npm run build

FROM composer:2 AS dependencies
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

FROM php:8.4-fpm-alpine
RUN apk add --no-cache {$packages} \
    && docker-php-ext-install {$extensions}
WORKDIR /var/www/html
COPY . .
COPY --from=dependencies /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
EXPOSE 8080
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
DOCKERFILE;
    }

    private function compose(BuildIteration $iteration): string
    {
        $database = $iteration->project->slug;

        if ($iteration->project->database_driver === 'sqlite') {
            return <<<'YAML'
services:
  app:
    build: .
    ports:
      - "8080:8080"
    environment:
      APP_ENV: local
      APP_DEBUG: "true"
      APP_KEY: ${APP_KEY}
      DB_CONNECTION: sqlite
      DB_DATABASE: /var/www/html/database/database.sqlite
    volumes:
      - sqlite-data:/var/www/html/database
volumes:
  sqlite-data:
YAML;
        }

        if ($iteration->project->database_driver === 'mysql') {
            return <<<YAML
services:
  app:
    build: .
    ports:
      - "8080:8080"
    environment:
      APP_ENV: local
      APP_DEBUG: "true"
      APP_KEY: \${APP_KEY}
      DB_CONNECTION: mysql
      DB_HOST: mysql
      DB_PORT: 3306
      DB_DATABASE: {$database}
      DB_USERNAME: {$database}
      DB_PASSWORD: local-development-only
    depends_on:
      mysql:
        condition: service_healthy
  mysql:
    image: mysql:8.4
    environment:
      MYSQL_DATABASE: {$database}
      MYSQL_USER: {$database}
      MYSQL_PASSWORD: local-development-only
      MYSQL_ROOT_PASSWORD: local-root-only
    volumes:
      - mysql-data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 5s
      timeout: 5s
      retries: 10
volumes:
  mysql-data:
YAML;
        }

        return <<<YAML
services:
  app:
    build: .
    ports:
      - "8080:8080"
    environment:
      APP_ENV: local
      APP_DEBUG: "true"
      APP_KEY: \${APP_KEY}
      DB_CONNECTION: pgsql
      DB_HOST: postgres
      DB_PORT: 5432
      DB_DATABASE: {$database}
      DB_USERNAME: {$database}
      DB_PASSWORD: local-development-only
    depends_on:
      postgres:
        condition: service_healthy
  postgres:
    image: postgres:17-alpine
    environment:
      POSTGRES_DB: {$database}
      POSTGRES_USER: {$database}
      POSTGRES_PASSWORD: local-development-only
    volumes:
      - postgres-data:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U {$database} -d {$database}"]
      interval: 5s
      timeout: 5s
      retries: 10
volumes:
  postgres-data:
YAML;
    }

    private function nginx(): string
    {
        return <<<'NGINX'
server {
    listen 8080;
    server_name _;
    root /var/www/html/public;
    index index.php;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass 127.0.0.1:9000;
    }
}
NGINX;
    }

    private function supervisor(): string
    {
        return <<<'SUPERVISOR'
[supervisord]
nodaemon=true
logfile=/dev/null

[program:php-fpm]
command=php-fpm -F
autorestart=true

[program:nginx]
command=nginx -g "daemon off;"
autorestart=true
SUPERVISOR;
    }

    private function testWorkflow(): string
    {
        return <<<'YAML'
name: tests
on:
  push:
  pull_request:
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          coverage: none
      - uses: actions/setup-node@v4
        with:
          node-version: '22'
          cache: npm
      - run: composer install --no-interaction --prefer-dist
      - run: cp .env.example .env
      - run: php artisan key:generate
      - run: touch database/database.sqlite
      - run: php artisan migrate --force
      - run: npm ci
      - run: npm run build
      - run: php artisan test
YAML;
    }

    private function publishImageWorkflow(): string
    {
        return <<<'YAML'
name: publish container
on:
  push:
    tags: ['v*']
permissions:
  contents: read
  packages: write
jobs:
  image:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: docker/login-action@v3
        with:
          registry: ghcr.io
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}
      - uses: docker/build-push-action@v6
        with:
          context: .
          push: true
          tags: ghcr.io/${{ github.repository }}:${{ github.ref_name }}
YAML;
    }
}
