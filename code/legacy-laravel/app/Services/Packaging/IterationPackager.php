<?php

namespace App\Services\Packaging;

use App\Models\BuildIteration;
use App\Models\BuildPackage;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use ZipArchive;

class IterationPackager
{
    public function zip(BuildIteration $iteration): BuildPackage
    {
        $validation = $iteration->runs()->where('type', 'validation')->latest()->first();
        if ($validation?->status !== 'passed' || $iteration->status !== 'generated') {
            throw new RuntimeException('The iteration must pass validation and be generated before packaging.');
        }

        $disk = Storage::disk('local');
        $source = "generated/{$iteration->project->slug}/iteration-{$iteration->number}";
        if (! $disk->exists("{$source}/visual-builder.json")) {
            throw new RuntimeException('The generated artifact bundle could not be found.');
        }

        $relativePath = "packages/{$iteration->project->slug}/iteration-{$iteration->number}.zip";
        $absolutePath = $disk->path($relativePath);
        if (! is_dir(dirname($absolutePath)) && ! mkdir(dirname($absolutePath), 0775, true) && ! is_dir(dirname($absolutePath))) {
            throw new RuntimeException('The package directory could not be created.');
        }

        $archive = new ZipArchive;
        if ($archive->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The ZIP package could not be opened.');
        }
        foreach ($disk->allFiles($source) as $file) {
            $archive->addFile($disk->path($file), substr($file, strlen($source) + 1));
        }
        if (! $archive->close()) {
            throw new RuntimeException('The ZIP package could not be finalized.');
        }

        return $this->record($iteration, 'zip', $absolutePath);
    }

    public function zipApplication(BuildIteration $iteration): BuildPackage
    {
        $assembly = $iteration->runs()->where('type', 'assembly')->latest()->first();
        if ($assembly?->status !== 'passed' || ! in_array($iteration->project->status, ['assembled', 'published'], true)) {
            throw new RuntimeException('Build and test the complete application before packaging it.');
        }
        $source = (string) $iteration->project->output_path;
        if (! is_file($source.DIRECTORY_SEPARATOR.'artisan')) {
            throw new RuntimeException('The assembled application folder could not be found.');
        }

        $disk = Storage::disk('local');
        $relativePath = "packages/{$iteration->project->slug}/iteration-{$iteration->number}-application.zip";
        $absolutePath = $disk->path($relativePath);
        if (! is_dir(dirname($absolutePath)) && ! mkdir(dirname($absolutePath), 0775, true) && ! is_dir(dirname($absolutePath))) {
            throw new RuntimeException('The package directory could not be created.');
        }
        $archive = new ZipArchive;
        if ($archive->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The application ZIP package could not be opened.');
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));
            if ($relative === '.env' || str_starts_with($relative, '.git/') || str_starts_with($relative, 'node_modules/')) {
                continue;
            }
            $archive->addFile($file->getPathname(), $relative);
        }
        if (! $archive->close()) {
            throw new RuntimeException('The application ZIP package could not be finalized.');
        }

        return $this->record($iteration, 'application-zip', $absolutePath);
    }

    private function record(BuildIteration $iteration, string $format, string $absolutePath): BuildPackage
    {
        $checksum = hash_file('sha256', $absolutePath);
        $bytes = filesize($absolutePath);
        if ($checksum === false || $bytes === false) {
            throw new RuntimeException('The ZIP package metadata could not be read.');
        }

        return $iteration->packages()->create([
            'format' => $format,
            'path' => $absolutePath,
            'checksum' => $checksum,
            'bytes' => $bytes,
            'packaged_at' => now(),
        ]);
    }
}
