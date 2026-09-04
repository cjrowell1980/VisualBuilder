<?php

namespace App\Services\Assembly;

use App\Contracts\ProcessRunner;
use App\Models\BuildIteration;
use App\Models\BuildRun;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Filesystem\Path;
use Throwable;

class LaravelProjectAssembler
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly Filesystem $files,
    ) {}

    public function assemble(BuildIteration $iteration): BuildRun
    {
        $run = $iteration->runs()->create(['type' => 'assembly', 'status' => 'running', 'started_at' => now()]);

        try {
            $iteration->load('project', 'plugins');
            $validationPassed = $iteration->runs()
                ->where('type', 'validation')
                ->latest()
                ->value('status') === 'passed';
            if (! $validationPassed || $iteration->status !== 'generated') {
                throw new RuntimeException('Validate and generate this iteration before assembling the project.');
            }

            $outputPath = $this->validateOutputPath($iteration);
            $parent = dirname($outputPath);
            $projectName = basename($outputPath);
            $scaffold = $this->runner->run([
                'laravel', 'new', $projectName, '--livewire', '--database='.$iteration->project->database_driver,
                '--no-node', '--git', '--no-interaction',
            ], $parent);
            if (! $scaffold['successful']) {
                throw new RuntimeException('Laravel scaffolding failed: '.$scaffold['output']);
            }

            $source = Storage::disk('local')->path("generated/{$iteration->project->slug}/iteration-{$iteration->number}");
            if (! is_file($source.DIRECTORY_SEPARATOR.'visual-builder.json')) {
                throw new RuntimeException('Generate the iteration before assembling the project.');
            }
            $this->files->copyDirectory($source, $outputPath);
            $this->wireGeneratedRoutes($outputPath);

            $commands = [];
            foreach ($iteration->plugins->where('approved', true) as $plugin) {
                $requirement = $plugin->package.($plugin->constraint ? ':'.$plugin->constraint : '');
                $commands[] = [['composer', 'require', $requirement], "Composer package {$plugin->package}"];
            }
            $commands = [...$commands,
                [['npm', 'install'], 'Frontend dependencies'],
                [['npm', 'run', 'build'], 'Frontend build'],
                [[PHP_BINARY, 'artisan', 'migrate', '--force'], 'Database migration'],
                [[PHP_BINARY, 'artisan', 'test'], 'Application tests'],
            ];
            $outputs = [$scaffold['output']];
            foreach ($commands as [$command, $label]) {
                $result = $this->runner->run($command, $outputPath);
                $outputs[] = "{$label}:\n{$result['output']}";
                if (! $result['successful']) {
                    throw new RuntimeException("{$label} failed: {$result['output']}");
                }
            }

            $run->update([
                'status' => 'passed',
                'checks' => [['level' => 'success', 'label' => 'Project assembly', 'message' => "Runnable application created at {$outputPath}"]],
                'output' => implode(PHP_EOL.PHP_EOL, $outputs),
                'finished_at' => now(),
            ]);
            $iteration->project->update(['status' => 'assembled']);
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'checks' => [['level' => 'error', 'label' => 'Project assembly', 'message' => $exception->getMessage()]],
                'output' => $exception->getMessage(),
                'finished_at' => now(),
            ]);
        }

        return $run->fresh();
    }

    private function validateOutputPath(BuildIteration $iteration): string
    {
        $configured = trim((string) $iteration->project->output_path);
        if ($configured === '' || ! Path::isAbsolute($configured)) {
            throw new RuntimeException('Choose an absolute output folder before assembling the project.');
        }
        $outputPath = Path::canonicalize($configured);
        if (file_exists($outputPath)) {
            throw new RuntimeException('The output folder already exists. Choose a new folder to prevent overwriting files.');
        }
        if (! is_dir(dirname($outputPath)) || ! is_writable(dirname($outputPath))) {
            throw new RuntimeException('The parent output folder does not exist or is not writable.');
        }

        return $outputPath;
    }

    private function wireGeneratedRoutes(string $outputPath): void
    {
        $routesPath = $outputPath.DIRECTORY_SEPARATOR.'routes'.DIRECTORY_SEPARATOR.'web.php';
        $contents = $this->files->get($routesPath);
        $require = "require __DIR__.'/generated.php';";
        if (! str_contains($contents, $require)) {
            $this->files->append($routesPath, PHP_EOL.$require.PHP_EOL);
        }
    }
}
