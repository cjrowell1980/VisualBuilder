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

class LaravelProjectUpdater
{
    /** @var list<string> */
    private array $writtenPaths = [];

    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly Filesystem $files,
        private readonly GeneratedProjectManifest $manifest,
    ) {}

    public function update(BuildIteration $iteration): BuildRun
    {
        $run = $iteration->runs()->create(['type' => 'update', 'status' => 'running', 'started_at' => now()]);
        $backupPath = null;

        try {
            $iteration->load('project', 'plugins');
            if ($iteration->status !== 'generated') {
                throw new RuntimeException('Validate and generate this iteration before updating the application.');
            }

            $outputPath = $this->validateOutputPath((string) $iteration->project->output_path);
            $source = Storage::disk('local')->path("generated/{$iteration->project->slug}/iteration-{$iteration->number}");
            if (! is_file($source.DIRECTORY_SEPARATOR.'visual-builder.json')) {
                throw new RuntimeException('Generate the iteration before updating the application.');
            }

            $previous = $this->manifest->read($outputPath);
            $current = $this->manifest->hashes($source);
            $definition = json_decode($this->files->get($source.DIRECTORY_SEPARATOR.'visual-builder.json'), true, flags: JSON_THROW_ON_ERROR);
            $schemaHash = hash('sha256', json_encode($definition['models'] ?? [], JSON_THROW_ON_ERROR));
            if (! hash_equals($previous['schema_hash'], $schemaHash)) {
                throw new RuntimeException('The data schema changed. Incremental schema migrations are not yet generated safely; build this iteration into a new folder instead.');
            }

            $updatable = fn (string $path): bool => ! str_starts_with($path, 'database/migrations/');
            $oldFiles = array_filter($previous['files'], $updatable, ARRAY_FILTER_USE_KEY);
            $newFiles = array_filter($current, $updatable, ARRAY_FILTER_USE_KEY);
            $conflicts = $this->conflicts($outputPath, $oldFiles, $newFiles);
            if ($conflicts !== []) {
                throw new RuntimeException('Update stopped because these generated files contain manual changes: '.implode(', ', $conflicts));
            }

            $backupPath = $this->backup($outputPath, array_values(array_unique([...array_keys($oldFiles), ...array_keys($newFiles)])), $iteration->number);
            $this->apply($source, $outputPath, $oldFiles, $newFiles);
            $this->manifest->write($source, $outputPath, $iteration->number);

            $outputs = [];
            foreach ($this->commands($iteration) as [$command, $label]) {
                $result = $this->runner->run($command, $outputPath);
                $outputs[] = "{$label}:\n{$result['output']}";
                if (! $result['successful']) {
                    throw new RuntimeException("{$label} failed: {$result['output']}");
                }
            }

            $message = "Iteration {$iteration->number} updated and tested at {$outputPath}. Backup: {$backupPath}";
            $run->update([
                'status' => 'passed',
                'checks' => [['level' => 'success', 'label' => 'Project update', 'message' => $message]],
                'output' => implode(PHP_EOL.PHP_EOL, $outputs),
                'finished_at' => now(),
            ]);
            $iteration->project->update(['status' => 'assembled']);
        } catch (Throwable $exception) {
            if ($backupPath !== null) {
                $this->restore($backupPath, (string) $iteration->project->output_path);
            }
            $run->update([
                'status' => 'failed',
                'checks' => [['level' => 'error', 'label' => 'Project update', 'message' => $exception->getMessage()]],
                'output' => $exception->getMessage(),
                'finished_at' => now(),
            ]);
        }

        return $run->fresh();
    }

    private function validateOutputPath(string $configured): string
    {
        if ($configured === '' || ! Path::isAbsolute($configured)) {
            throw new RuntimeException('Choose an absolute application output folder before updating.');
        }
        $outputPath = Path::canonicalize($configured);
        if (! is_dir($outputPath) || ! is_file($outputPath.DIRECTORY_SEPARATOR.'artisan')) {
            throw new RuntimeException('The configured output folder is not an assembled Laravel application.');
        }

        return $outputPath;
    }

    /**
     * @param  array<string, string>  $oldFiles
     * @param  array<string, string>  $newFiles
     * @return list<string>
     */
    private function conflicts(string $outputPath, array $oldFiles, array $newFiles): array
    {
        $conflicts = [];
        foreach (array_unique([...array_keys($oldFiles), ...array_keys($newFiles)]) as $relative) {
            $destination = $outputPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (! is_file($destination)) {
                continue;
            }
            $destinationHash = hash_file('sha256', $destination);
            $oldHash = $oldFiles[$relative] ?? null;
            $newHash = $newFiles[$relative] ?? null;
            if ($oldHash === null || ($destinationHash !== $oldHash && $destinationHash !== $newHash)) {
                $conflicts[] = $relative;
            }
        }

        return $conflicts;
    }

    /** @param list<string> $paths */
    private function backup(string $outputPath, array $paths, int $iteration): string
    {
        $backup = $outputPath.DIRECTORY_SEPARATOR.'.visual-builder'.DIRECTORY_SEPARATOR.'backups'.DIRECTORY_SEPARATOR.'iteration-'.$iteration.'-'.now()->format('Ymd-His');
        foreach ($paths as $relative) {
            $source = $outputPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (is_file($source)) {
                $destination = $backup.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $this->files->ensureDirectoryExists(dirname($destination));
                $this->files->copy($source, $destination);
            }
        }
        $this->files->copy($this->manifest->path($outputPath), $backup.DIRECTORY_SEPARATOR.'generated-manifest.json');

        return $backup;
    }

    /**
     * @param  array<string, string>  $oldFiles
     * @param  array<string, string>  $newFiles
     */
    private function apply(string $source, string $outputPath, array $oldFiles, array $newFiles): void
    {
        $this->writtenPaths = array_keys($newFiles);
        foreach (array_diff(array_keys($oldFiles), array_keys($newFiles)) as $relative) {
            $this->files->delete($outputPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative));
        }
        foreach (array_keys($newFiles) as $relative) {
            $from = $source.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $to = $outputPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $this->files->ensureDirectoryExists(dirname($to));
            $this->files->copy($from, $to);
        }
    }

    private function restore(string $backup, string $outputPath): void
    {
        foreach ($this->writtenPaths as $relative) {
            $this->files->delete($outputPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative));
        }
        foreach ($this->files->allFiles($backup) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            if ($relative === 'generated-manifest.json') {
                $destination = $this->manifest->path($outputPath);
            } else {
                $destination = $outputPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            }
            $this->files->ensureDirectoryExists(dirname($destination));
            $this->files->copy($file->getPathname(), $destination);
        }
    }

    /** @return list<array{0: list<string>, 1: string}> */
    private function commands(BuildIteration $iteration): array
    {
        $commands = [];
        foreach ($iteration->plugins->where('approved', true)->where('type', 'composer') as $plugin) {
            $commands[] = [['composer', 'require', $plugin->package.($plugin->constraint ? ':'.$plugin->constraint : '')], "Composer package {$plugin->package}"];
        }
        $commands[] = [['npm', 'install'], 'Frontend dependencies'];
        foreach ($iteration->plugins->where('approved', true)->where('type', 'npm') as $plugin) {
            $constraint = $plugin->constraint && $plugin->constraint !== '*' ? '@'.$plugin->constraint : '';
            $commands[] = [['npm', 'install', $plugin->package.$constraint], "npm package {$plugin->package}"];
        }

        return [...$commands, [['npm', 'run', 'build'], 'Frontend build'], [[PHP_BINARY, 'artisan', 'test'], 'Application tests']];
    }
}
