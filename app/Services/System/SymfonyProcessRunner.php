<?php

namespace App\Services\System;

use App\Contracts\ProcessRunner;
use Symfony\Component\Process\Process;

class SymfonyProcessRunner implements ProcessRunner
{
    public function run(array $command, string $workingDirectory, int $timeout = 600): array
    {
        $process = new Process($command, $workingDirectory);
        $process->setTimeout($timeout)->run();

        return [
            'successful' => $process->isSuccessful(),
            'output' => trim($process->getOutput().PHP_EOL.$process->getErrorOutput()),
            'exit_code' => $process->getExitCode(),
        ];
    }
}
