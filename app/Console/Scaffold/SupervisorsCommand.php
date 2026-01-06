<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Scaffold;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\Traits\ScaffoldsTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'scaffold:supervisors',
    description: 'Scaffold supervisor program scripts from templates'
)]
class SupervisorsCommand extends BaseCommand
{
    use ScaffoldsTrait;

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();
        $this->configureScaffoldOptions();
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Scaffold Supervisor Scripts');

        return $this->scaffoldFiles('supervisors');
    }
}
