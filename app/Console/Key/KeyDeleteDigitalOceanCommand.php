<?php

declare(strict_types=1);

namespace Deployer\Console\Key;

use Deployer\Contracts\BaseCommand;
use Deployer\Traits\DigitalOceanTrait;
use Deployer\Traits\KeysTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'key:delete:digitalocean',
    description: 'Delete a public SSH key from DigitalOcean'
)]
class KeyDeleteDigitalOceanCommand extends BaseCommand
{
    use DigitalOceanTrait;
    use KeysTrait;

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'DigitalOcean public SSH key ID')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip typing the key ID to confirm')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip Yes/No confirmation prompt');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Delete a public SSH key from DigitalOcean');

        //
        // Retrieve DigitalOcean account data
        // ----

        if ($this->initializeDigitalOceanAPI() === Command::FAILURE) {
            return Command::FAILURE;
        }

        //
        // Select key
        // ----

        $selectedKey = $this->selectKey();

        if (is_int($selectedKey)) {
            return Command::FAILURE;
        }

        [
            'id' => $keyId,
            'description' => $keyDescription,
        ] = $selectedKey;

        //
        // Display key
        // ----

        $this->displayDeets([
            'ID' => (string) $keyId,
            'Name' => $keyDescription,
        ]);

        $this->out('───');
        $this->io->write('', true);

        //
        // Confirm deletion with extra safety
        // ----

        /** @var bool $forceSkip */
        $forceSkip = $input->getOption('force') ?? false;

        if (!$forceSkip) {
            $typedKeyId = $this->io->promptText(
                label: "Type the key ID '{$keyId}' to confirm deletion:",
                required: true
            );

            if ($typedKeyId !== (string) $keyId) {
                $this->nay('Key ID does not match. Deletion cancelled.');

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
            $this->warn('Cancelled deleting public SSH key');

            return Command::SUCCESS;
        }

        //
        // Delete key
        // ----

        try {
            $this->io->promptSpin(
                fn () => $this->digitalOcean->key->deletePublicKey((int) $keyId),
                'Deleting public SSH key...'
            );

            $this->yay('Public SSH key deleted successfully');
        } catch (\RuntimeException $e) {
            $this->nay('Failed to delete public SSH key: ' . $e->getMessage());

            return Command::FAILURE;
        }

        //
        // Show command replay
        // ----

        $this->commandReplay('key:delete:digitalocean', [
            'key' => (string) $keyId,
            'force' => true,
            'yes' => $confirmed,
        ]);

        return Command::SUCCESS;
    }

}
