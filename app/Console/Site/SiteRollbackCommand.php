<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Site;

use DeployerPHP\Contracts\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'site:rollback',
    description: 'Learn about forward-only deployments'
)]
class SiteRollbackCommand extends BaseCommand
{
    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Rollback Site');

        $this->info('Forward-only deployments:');

        $this->ul([
            'Rollbacks mask problems rather than fixing them — the underlying issue remains',
            'Forward-only fixes create an auditable history of what changed and why',
            'Modern CI/CD makes deploying a fix just as fast as rolling back',
        ]);

        return Command::SUCCESS;
    }
}
