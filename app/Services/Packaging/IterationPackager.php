<?php

namespace App\Services\Packaging;

use App\Models\BuildIteration;
use App\Models\BuildPackage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class IterationPackager
{
    public function zip(BuildIteration $iteration): BuildPackage
    {
        $latestRun = $iteration->runs()->first();
        if ($latestRun?->status !== 'passed' || $iteration->status !== 'generated') {
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
        $checksum = hash_file('sha256', $absolutePath);
        $bytes = filesize($absolutePath);
        if ($checksum === false || $bytes === false) {
            throw new RuntimeException('The ZIP package metadata could not be read.');
        }

        return $iteration->packages()->create([
            'format' => 'zip',
            'path' => $absolutePath,
            'checksum' => $checksum,
            'bytes' => $bytes,
            'packaged_at' => now(),
        ]);
    }
}
