<?php

namespace App\Services\Debugging;

use App\Models\BuildIteration;
use App\Models\BuildRun;
use Native\Desktop\Contracts\ChildProcess;
use Native\Desktop\Contracts\Shell;
use RuntimeException;
use Throwable;

class PreviewServerManager
{
    public function __construct(
        private readonly ChildProcess $processes,
        private readonly Shell $shell,
    ) {}

    public function start(BuildIteration $iteration): BuildRun
    {
        $run = $iteration->runs()->create(['type' => 'preview', 'status' => 'running', 'started_at' => now()]);

        try {
            $path = (string) $iteration->project->output_path;
            if ($iteration->project->status !== 'assembled' || ! is_file($path.DIRECTORY_SEPARATOR.'artisan')) {
                throw new RuntimeException('Build and test the runnable application before launching its debugger.');
            }

            $url = $this->url($iteration);
            $this->processes->start(
                [PHP_BINARY, 'artisan', 'serve', '--host=127.0.0.1', '--port='.$this->port($iteration)],
                $this->alias($iteration),
                $path,
            );
            $run->update([
                'checks' => [['level' => 'success', 'label' => 'Preview server', 'message' => "Debugger running at {$url}"]],
                'output' => $url,
            ]);
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'checks' => [['level' => 'error', 'label' => 'Preview server', 'message' => $exception->getMessage()]],
                'output' => $exception->getMessage(),
                'finished_at' => now(),
            ]);
        }

        return $run->fresh();
    }

    public function open(BuildIteration $iteration): void
    {
        $this->shell->openExternal($this->url($iteration));
    }

    public function stop(BuildIteration $iteration): void
    {
        $this->processes->stop($this->alias($iteration));
        $iteration->runs()->where('type', 'preview')->where('status', 'running')->update([
            'status' => 'stopped',
            'finished_at' => now(),
        ]);
    }

    public function url(BuildIteration $iteration): string
    {
        return 'http://127.0.0.1:'.$this->port($iteration);
    }

    private function port(BuildIteration $iteration): int
    {
        return 8100 + ($iteration->project->id % 500);
    }

    private function alias(BuildIteration $iteration): string
    {
        return 'visual-builder-preview-'.$iteration->project->id;
    }
}
