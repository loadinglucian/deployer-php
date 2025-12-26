<?php

declare(strict_types=1);

namespace Deployer\Console\Pro\Do;

use Deployer\Contracts\BaseCommand;
use Deployer\Traits\DoTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'pro:do:key:list',
    description: 'List public SSH keys in DigitalOcean'
)]
class KeyListCommand extends BaseCommand
{
    use DoTrait;

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('List Public SSH Keys in DigitalOcean');

        //
        // Retrieve DigitalOcean account data
        // ----

        if (Command::FAILURE === $this->initializeDoAPI()) {
            return Command::FAILURE;
        }

        $keys = $this->ensureDoKeysAvailable();

        if (is_int($keys)) {
            return Command::FAILURE;
        }

        $this->displayDeets($keys);

        //
        // Show command replay
        // ----

        $this->commandReplay('pro:do:key:list', []);

        return Command::SUCCESS;
    }
}
