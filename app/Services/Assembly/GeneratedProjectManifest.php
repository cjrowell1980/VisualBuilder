<?php

namespace App\Services\Assembly;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

class GeneratedProjectManifest
{
    public function __construct(private readonly Filesystem $files) {}

    public function path(string $outputPath): string
    {
        return $outputPath.DIRECTORY_SEPARATOR.'.visual-builder'.DIRECTORY_SEPARATOR.'generated-manifest.json';
    }

    /** @return array{iteration: int, files: array<string, string>, schema_hash: string} */
    public function read(string $outputPath): array
    {
        $path = $this->path($outputPath);
        if (! is_file($path)) {
            throw new RuntimeException('This project has no VisualBuilder update baseline. Rebuild it once before applying updates.');
        }

        $manifest = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($manifest)
            || ! is_int($manifest['iteration'] ?? null)
            || ! is_array($manifest['files'] ?? null)
            || ! is_string($manifest['schema_hash'] ?? null)) {
            throw new RuntimeException('The VisualBuilder update baseline is invalid.');
        }

        $files = [];
        foreach ($manifest['files'] as $path => $hash) {
            if (! is_string($path) || ! is_string($hash)) {
                throw new RuntimeException('The VisualBuilder update baseline contains an invalid file hash.');
            }
            $files[$path] = $hash;
        }

        return ['iteration' => $manifest['iteration'], 'files' => $files, 'schema_hash' => $manifest['schema_hash']];
    }

    public function write(string $source, string $outputPath, int $iteration): void
    {
        $definition = json_decode($this->files->get($source.DIRECTORY_SEPARATOR.'visual-builder.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest = [
            'iteration' => $iteration,
            'files' => $this->hashes($source),
            'schema_hash' => hash('sha256', json_encode($definition['models'] ?? [], JSON_THROW_ON_ERROR)),
            'created_at' => now()->toIso8601String(),
        ];
        $path = $this->path($outputPath);
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    }

    /** @return array<string, string> */
    public function hashes(string $directory): array
    {
        $hashes = [];
        foreach ($this->files->allFiles($directory) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $hash = hash_file('sha256', $file->getPathname());
            if ($hash === false) {
                throw new RuntimeException("Unable to hash generated file: {$relative}");
            }
            $hashes[$relative] = $hash;
        }
        ksort($hashes);

        return $hashes;
    }
}
