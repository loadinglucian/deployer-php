<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Pro\Aws;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\Traits\AwsTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'pro:aws:key:delete|aws:key:delete',
    description: 'Delete a key pair from AWS'
)]
class KeyDeleteCommand extends BaseCommand
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

        if (Command::FAILURE === $this->initializeAwsAPI()) {
            return Command::FAILURE;
        }

        $this->info("Region: {$this->aws->getRegion()}");

        $selectedKey = $this->selectAwsKey();

        if (is_int($selectedKey)) {
            return Command::FAILURE;
        }

        ['name' => $keyName, 'description' => $keyDescription] = $selectedKey;

        $this->displayDeets([
            'Name' => $keyName,
            'Description' => $keyDescription,
        ]);

        $this->out('───');
        $this->io->write("\n");

        /** @var bool $forceSkip */
        $forceSkip = $input->getOption('force') ?? false;

        $confirmed = $this->confirmDeletion($keyName, $forceSkip);

        if (null === $confirmed) {
            return Command::FAILURE;
        }

        if (!$confirmed) {
            $this->warn('Cancelled deleting key pair');

            return Command::SUCCESS;
        }

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

        $this->commandReplay('pro:aws:key:delete', [
            'key' => $keyName,
            'force' => true,
            'yes' => $confirmed,
        ]);

        return Command::SUCCESS;
    }

    // ----
    // Helpers
    // ----

    /**
     * Confirm key pair deletion with type-to-confirm and yes/no prompt.
     *
     * @return bool|null True if confirmed, false if cancelled, null if validation failed
     */
    protected function confirmDeletion(string $keyName, bool $forceSkip): ?bool
    {
        if (!$forceSkip) {
            $typedKeyName = $this->io->promptText(
                label: "Type the key pair name '{$keyName}' to confirm deletion:",
                required: true
            );

            if ($typedKeyName !== $keyName) {
                $this->nay('Key pair name does not match. Deletion cancelled.');

                return null;
            }
        }

        return $this->io->getBooleanOptionOrPrompt(
            'yes',
            fn (): bool => $this->io->promptConfirm(
                label: 'Are you absolutely sure?',
                default: false
            )
        );
    }
}
