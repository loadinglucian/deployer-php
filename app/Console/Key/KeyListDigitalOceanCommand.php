<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Console\Key;

use Bigpixelrocket\DeployerPHP\Contracts\BaseCommand;
use Bigpixelrocket\DeployerPHP\Traits\DigitalOceanCommandTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * List SSH keys in the user's DigitalOcean account.
 */
#[AsCommand(
    name: 'key:list:digitalocean',
    description: 'List SSH keys in DigitalOcean'
)]
class KeyListDigitalOceanCommand extends BaseCommand
{
    use DigitalOceanCommandTrait;

    //
    // Execution
    // -------------------------------------------------------------------------------

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->io->hr();
        $this->io->h1('List SSH Keys in DigitalOcean');

        if ($this->initializeDigitalOceanAPI() === Command::FAILURE) {
            return Command::FAILURE;
        }

        //
        // Fetch SSH keys
        // -------------------------------------------------------------------------------

        try {
            $keys = $this->io->promptSpin(
                fn () => $this->digitalOcean->account->getUserSshKeys(),
                'Fetching SSH keys...'
            );
        } catch (\RuntimeException $e) {
            $this->io->error('Failed to fetch SSH keys: ' . $e->getMessage());
            $this->io->writeln('');

            return Command::FAILURE;
        }

        //
        // Display keys
        // -------------------------------------------------------------------------------

        if (count($keys) === 0) {
            $this->io->warning('No SSH keys found in your DigitalOcean account');
            $this->io->writeln([
                '',
                'Use <fg=cyan>key:add:digitalocean</> to add an SSH key',
                '',
            ]);

            return Command::SUCCESS;
        }

        foreach ($keys as $keyId => $description) {
            $this->io->writeln("  <fg=cyan>{$keyId}</> - {$description}");
        }

        $this->io->writeln('');

        return Command::SUCCESS;
    }
}
