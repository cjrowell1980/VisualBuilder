<?php

namespace App\Contracts;

interface ProcessRunner
{
    /** @param list<string> $command
     * @return array{successful: bool, output: string, exit_code: int|null}
     */
    public function run(array $command, string $workingDirectory, int $timeout = 600): array;
}
