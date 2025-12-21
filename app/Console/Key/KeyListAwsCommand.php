<?php

declare(strict_types=1);

namespace Deployer\Console\Key;

use Deployer\Contracts\BaseCommand;
use Deployer\Traits\AwsTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'key:list:aws',
    description: 'List EC2 key pairs in AWS'
)]
class KeyListAwsCommand extends BaseCommand
{
    use AwsTrait;

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('List EC2 Key Pairs in AWS');

        //
        // Initialize AWS API
        // ----

        if (Command::FAILURE === $this->initializeAwsAPI()) {
            return Command::FAILURE;
        }

        //
        // Fetch keys
        // ----

        $keys = $this->ensureAwsKeysAvailable();

        if (is_int($keys)) {
            return Command::FAILURE;
        }

        $this->info("Region: {$this->aws->getRegion()}");
        $this->displayDeets($keys);

        //
        // Show command replay
        // ----

        $this->commandReplay('key:list:aws', []);

        return Command::SUCCESS;
    }
}
