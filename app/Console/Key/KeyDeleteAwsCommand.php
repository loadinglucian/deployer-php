<?php

declare(strict_types=1);

namespace Deployer\Console\Key;

use Deployer\Contracts\BaseCommand;
use Deployer\Traits\AwsTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'key:delete:aws',
    description: 'Delete a key pair from AWS'
)]
class KeyDeleteAwsCommand extends BaseCommand
{
    use AwsTrait;

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'AWS key pair name')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip typing the key name to confirm')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip Yes/No confirmation prompt');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Delete a Key Pair from AWS');

        //
        // Initialize AWS API
        // ----

        if (Command::FAILURE === $this->initializeAwsAPI()) {
            return Command::FAILURE;
        }

        //
        // Select key
        // ----

        $selectedKey = $this->selectAwsKey();

        if (is_int($selectedKey)) {
            return Command::FAILURE;
        }

        [
            'name' => $keyName,
            'description' => $keyDescription,
        ] = $selectedKey;

        $this->displayDeets([
            'Name' => $keyName,
            'Description' => $keyDescription,
            'Region' => $this->aws->getRegion(),
        ]);

        $this->out('───');
        $this->io->write("\n");

        //
        // Confirm deletion with extra safety
        // ----

        /** @var bool $forceSkip */
        $forceSkip = $input->getOption('force') ?? false;

        if (!$forceSkip) {
            $typedKeyName = $this->io->promptText(
                label: "Type the key pair name '{$keyName}' to confirm deletion:",
                required: true
            );

            if ($typedKeyName !== $keyName) {
                $this->nay('Key pair name does not match. Deletion cancelled.');

                return Command::FAILURE;
            }
        }

        $confirmed = $this->io->getBooleanOptionOrPrompt(
            'yes',
            fn (): bool => $this->io->promptConfirm(
                label: 'Are you absolutely sure?',
                default: false
            )
        );

        if (!$confirmed) {
            $this->warn('Cancelled deleting key pair');

            return Command::SUCCESS;
        }

        //
        // Delete key
        // ----

        try {
            $this->io->promptSpin(
                fn () => $this->aws->key->deleteKeyPair($keyName),
                'Deleting key pair...'
            );

            $this->yay('Key pair deleted successfully');
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        //
        // Show command replay
        // ----

        $this->commandReplay('key:delete:aws', [
            'key' => $keyName,
            'force' => true,
            'yes' => $confirmed,
        ]);

        return Command::SUCCESS;
    }
}
