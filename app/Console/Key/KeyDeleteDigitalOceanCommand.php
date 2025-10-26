<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Console\Key;

use Bigpixelrocket\DeployerPHP\Contracts\BaseCommand;
use Bigpixelrocket\DeployerPHP\Traits\DigitalOceanCommandTrait;
use Bigpixelrocket\DeployerPHP\Traits\KeyHelpersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Delete a SSH key from the user's DigitalOcean account.
 */
#[AsCommand(
    name: 'key:delete:digitalocean',
    description: 'Delete a SSH key from DigitalOcean'
)]
class KeyDeleteDigitalOceanCommand extends BaseCommand
{
    use DigitalOceanCommandTrait;
    use KeyHelpersTrait;

    //
    // Configuration
    // -------------------------------------------------------------------------------

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'SSH key ID')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip typing key ID (use with caution)')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    //
    // Execution
    // -------------------------------------------------------------------------------

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->io->hr();
        $this->io->h1('Delete SSH Key from DigitalOcean');

        if ($this->initializeDigitalOceanAPI() === Command::FAILURE) {
            return Command::FAILURE;
        }

        //
        // Fetch available keys

        try {
            $availableKeys = $this->digitalOcean->account->getUserSshKeys();
        } catch (\RuntimeException $e) {
            $this->io->error('Failed to fetch SSH keys: ' . $e->getMessage());
            $this->io->writeln('');

            return Command::FAILURE;
        }

        //
        // Select key

        $selection = $this->selectKey($availableKeys);

        if ($selection['key'] === null) {
            return $selection['exit_code'];
        }

        $keyId = (int) $selection['key'];
        $keyDescription = $availableKeys[$keyId];

        //
        // Display key details

        $this->io->hr();

        $this->io->writeln([
            "  ID:   <fg=gray>{$keyId}</>",
            "  Name: <fg=gray>{$keyDescription}</>",
            '',
        ]);

        //
        // Confirm deletion with extra safety

        /** @var bool $forceSkip */
        $forceSkip = $input->getOption('force') ?? false;

        if (!$forceSkip) {
            $this->io->writeln('');

            $typedKeyId = $this->io->promptText(
                label: "Type the key ID '{$keyId}' to confirm deletion:",
                required: true
            );

            if ($typedKeyId !== (string) $keyId) {
                $this->io->error('Key ID does not match. Deletion cancelled.');
                $this->io->writeln('');

                return Command::FAILURE;
            }
        }

        /** @var bool $confirmed */
        $confirmed = $this->io->getOptionOrPrompt(
            'yes',
            fn (): bool => $this->io->promptConfirm(
                label: 'Are you absolutely sure?',
                default: false
            )
        );

        if (!$confirmed) {
            $this->io->warning('Cancelled deleting SSH key');
            $this->io->writeln('');

            return Command::SUCCESS;
        }

        //
        // Delete key

        try {
            $this->io->promptSpin(
                fn () => $this->digitalOcean->key->deleteKey($keyId),
                'Deleting SSH key...'
            );

            $this->io->success('SSH key deleted successfully');
            $this->io->writeln('');
        } catch (\RuntimeException $e) {
            $this->io->error('Failed to delete SSH key: ' . $e->getMessage());
            $this->io->writeln('');

            return Command::FAILURE;
        }

        //
        // Show command hint

        $this->io->showCommandHint('key:delete:digitalocean', [
            'key' => (string) $keyId,
            'yes' => $confirmed,
            'force' => true,
        ]);

        return Command::SUCCESS;
    }
}
