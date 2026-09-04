<?php

namespace App\Services\Generation;

use App\Models\BuildIteration;
use App\Models\ControlDefinition;
use App\Models\ModelDefinition;
use App\Models\ModelRelationship;
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
{$relations}
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
        }

        return <<<PHP
    public function {$relationship->name}(): {$returnType}
    {
        return \$this->{$relationship->type}({$arguments});
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
            $chain .= $field->unique ? '->unique()' : '';

            return "            \$table->{$method}('{$field->name}'){$chain};";
        })->implode("\n");
        $foreignKeys = $model->relationships
            ->where('type', 'belongsTo')
            ->map(fn (ModelRelationship $relationship): string => "            \$table->foreignId('{$relationship->foreign_key}')->constrained('{$relationship->target->table_name}');")
            ->implode("\n");
        $foreignKeys = $foreignKeys === '' ? '' : $foreignKeys."\n";
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

    private function page(PageDefinition $page): string
    {
        $model = $page->modelDefinition;
        $boundFields = $page->controls->pluck('field')->filter()->unique('id')->values();
        $properties = $boundFields->map(fn ($field): string => "    public mixed \${$field->name} = null;")->implode("\n");
        $properties = $properties === '' ? '' : "\n{$properties}\n";
        $save = '';
        $modelImport = '';
        if ($model && $boundFields->isNotEmpty()) {
            $modelImport = "use App\\Models\\{$model->name};\n";
            $rules = $boundFields->mapWithKeys(function ($field): array {
                $rules = $field->validation_rules ?: [$field->nullable ? 'nullable' : 'required'];

                return [$field->name => $rules];
            })->all();
            $rulesCode = var_export($rules, true);
            $fieldNames = $boundFields->pluck('name')->map(fn (string $name): string => "'{$name}'")->implode(', ');
            $save = <<<PHP

    public function save(): void
    {
        \$validated = \$this->validate({$rulesCode});
        {$model->name}::query()->create(\$validated);
        \$this->reset({$fieldNames});
        session()->flash('status', '{$model->name} saved.');
    }
PHP;
        }
        $controls = $page->controls->map(fn (ControlDefinition $control) => $this->control($control, $save !== ''))->implode("\n\n");
        $title = e($page->name);

        return <<<BLADE
<?php

{$modelImport}use Livewire\Component;

new class extends Component
{{$properties}{$save}
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

    private function control(ControlDefinition $control, bool $hasSave): string
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
            'button' => $hasSave
                ? "    <flux:button wire:click=\"save\" variant=\"primary\">{$label}</flux:button>"
                : "    <flux:button variant=\"primary\">{$label}</flux:button>",
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

Route::middleware(['auth'])->group(function (): void {
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
