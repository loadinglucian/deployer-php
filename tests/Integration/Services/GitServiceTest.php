<?php

declare(strict_types=1);

use DeployerPHP\Services\FilesystemService;
use DeployerPHP\Services\GitService;
use DeployerPHP\Services\ProcessService;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('returns file-only results for remote script paths', function (): void {
    $filesystem = new FilesystemService(new Filesystem());
    $process = new ProcessService($filesystem);
    $git = new GitService($process, $filesystem);

    $runCommand = static function (array $command, string $cwd): Process {
        $process = new Process($command, $cwd);
        $process->run();

        expect(
            $process->isSuccessful()
        )->toBeTrue();

        return $process;
    };

    $tmpRoot = sys_get_temp_dir().'/deployer-git-service-test-'.bin2hex(random_bytes(8));
    $origin = $tmpRoot.'/origin.git';
    $worktree = $tmpRoot.'/worktree';

    $filesystem->mkdir($tmpRoot);

    try {
        $runCommand(['git', 'init', '--bare', $origin], $tmpRoot);
        $runCommand(['git', 'clone', $origin, $worktree], $tmpRoot);
        $runCommand(['git', 'config', 'user.email', 'test@example.com'], $worktree);
        $runCommand(['git', 'config', 'user.name', 'Test User'], $worktree);

        $filesystem->mkdir($worktree.'/.deployer/scripts');
        $filesystem->dumpFile(
            $worktree.'/.deployer/scripts/cron',
            "#!/usr/bin/env bash\necho 'ok'\n"
        );
        $filesystem->dumpFile(
            $worktree.'/.deployer/scripts/cron.sh',
            "#!/usr/bin/env bash\necho 'ok'\n"
        );

        $runCommand(['git', 'add', '.'], $worktree);
        $runCommand(['git', 'commit', '-m', 'Add cron script'], $worktree);

        $branchProcess = $runCommand(['git', 'branch', '--show-current'], $worktree);
        $branch = trim($branchProcess->getOutput());
        expect($branch)->not->toBe('');

        $runCommand(['git', 'push', 'origin', $branch], $worktree);

        $checks = $git->checkRemoteFilesExist($origin, $branch, [
            '.deployer/scripts',
            '.deployer/scripts/cron',
            '.deployer/scripts/cron.sh',
            '.deployer/scripts/missing.sh',
        ]);

        expect($checks)->toBe([
            '.deployer/scripts' => false,
            '.deployer/scripts/cron' => true,
            '.deployer/scripts/cron.sh' => true,
            '.deployer/scripts/missing.sh' => false,
        ]);
    } finally {
        $filesystem->remove($tmpRoot);
    }
});
