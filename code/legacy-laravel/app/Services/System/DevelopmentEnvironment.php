<?php

namespace App\Services\System;

use Symfony\Component\Process\Process;

class DevelopmentEnvironment
{
    /** @return array<string, array{label: string, available: bool, version: string}> */
    public function capabilities(): array
    {
        return [
            'php' => $this->probe('PHP', [PHP_BINARY, '--version']),
            'composer' => $this->probe('Composer', ['composer', '--version']),
            'node' => $this->probe('Node.js', ['node', '--version']),
            'git' => $this->probe('Git', ['git', '--version']),
            'github' => $this->probe('GitHub CLI', ['gh', '--version']),
            'docker' => $this->probe('Docker', ['docker', '--version']),
        ];
    }

    /** @param list<string> $command
     * @return array{label: string, available: bool, version: string}
     */
    private function probe(string $label, array $command): array
    {
        try {
            $process = new Process($command);
            $process->setTimeout(3)->run();
            $output = trim($process->getOutput() ?: $process->getErrorOutput());

            return [
                'label' => $label,
                'available' => $process->isSuccessful(),
                'version' => strtok($output, "\r\n") ?: 'Unavailable',
            ];
        } catch (\Throwable) {
            return ['label' => $label, 'available' => false, 'version' => 'Unavailable'];
        }
    }
}
