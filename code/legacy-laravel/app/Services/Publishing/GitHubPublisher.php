<?php

namespace App\Services\Publishing;

use App\Contracts\ProcessRunner;
use App\Models\BuildIteration;
use App\Models\BuildRun;
use RuntimeException;
use Throwable;

class GitHubPublisher
{
    public function __construct(private readonly ProcessRunner $runner) {}

    public function publish(BuildIteration $iteration, string $repository): BuildRun
    {
        $run = $iteration->runs()->create(['type' => 'github', 'status' => 'running', 'started_at' => now()]);

        try {
            $iteration->load('project');
            if ($iteration->project->status !== 'assembled') {
                throw new RuntimeException('Build and test the application before publishing it.');
            }
            if (! preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository)) {
                throw new RuntimeException('Enter the GitHub repository as owner/name.');
            }
            $path = (string) $iteration->project->output_path;
            if (! is_file($path.DIRECTORY_SEPARATOR.'artisan')) {
                throw new RuntimeException('The assembled application folder could not be found.');
            }

            $this->mustRun(['gh', 'auth', 'status'], $path, 'GitHub authentication');
            $this->mustRun(['git', 'add', '--all'], $path, 'Git staging');
            $commit = $this->runner->run(['git', 'commit', '-m', "Build iteration {$iteration->number}"], $path);
            $nothingToCommit = str_contains(strtolower($commit['output']), 'nothing to commit')
                || str_contains(strtolower($commit['output']), 'no changes added to commit');
            if (! $commit['successful'] && ! $nothingToCommit) {
                throw new RuntimeException('Git commit failed: '.$commit['output']);
            }
            $exists = $this->runner->run(['gh', 'repo', 'view', $repository], $path)['successful'];

            if (! $exists) {
                $this->mustRun(['gh', 'repo', 'create', $repository, '--private', '--source=.', '--remote=origin', '--push'], $path, 'GitHub repository creation');
            } else {
                $remote = $this->runner->run(['git', 'remote', 'get-url', 'origin'], $path);
                if (! $remote['successful']) {
                    $this->mustRun(['git', 'remote', 'add', 'origin', "https://github.com/{$repository}.git"], $path, 'Git remote setup');
                } elseif (! str_contains($remote['output'], $repository)) {
                    throw new RuntimeException('The existing origin remote points to a different repository.');
                }
                $this->mustRun(['git', 'push', '--set-upstream', 'origin', 'HEAD'], $path, 'GitHub push');
            }

            $message = $commit['successful'] ? 'Changes committed and pushed.' : 'No new commit was required; the current branch was pushed.';
            $run->update([
                'status' => 'passed',
                'checks' => [['level' => 'success', 'label' => 'GitHub delivery', 'message' => $message]],
                'output' => "https://github.com/{$repository}",
                'finished_at' => now(),
            ]);
            $iteration->project->update(['github_repository' => $repository, 'status' => 'published']);
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'checks' => [['level' => 'error', 'label' => 'GitHub delivery', 'message' => $exception->getMessage()]],
                'output' => $exception->getMessage(),
                'finished_at' => now(),
            ]);
        }

        return $run->fresh();
    }

    /** @param list<string> $command */
    private function mustRun(array $command, string $path, string $label): void
    {
        $result = $this->runner->run($command, $path);
        if (! $result['successful']) {
            throw new RuntimeException("{$label} failed: {$result['output']}");
        }
    }
}
