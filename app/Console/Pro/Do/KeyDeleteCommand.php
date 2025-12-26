<?php

declare(strict_types=1);

namespace Deployer\Console\Pro\Do;

use Deployer\Contracts\BaseCommand;
use Deployer\Traits\DoTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'pro:do:key:delete',
    description: 'Delete a public SSH key from DigitalOcean'
)]
class KeyDeleteCommand extends BaseCommand
{
    use DoTrait;

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

        if (Command::FAILURE === $this->initializeDoAPI()) {
            return Command::FAILURE;
        }

        $selectedKey = $this->selectDoKey();

        if (is_int($selectedKey)) {
            return Command::FAILURE;
        }

        ['id' => $keyId, 'description' => $keyDescription] = $selectedKey;

        $this->displayDeets([
            'ID' => (string) $keyId,
            'Name' => $keyDescription,
        ]);

        $this->out('───');
        $this->io->write("\n");

        /** @var bool $forceSkip */
        $forceSkip = $input->getOption('force') ?? false;

        $confirmed = $this->confirmDeletion((string) $keyId, $forceSkip);

        if (null === $confirmed) {
            return Command::FAILURE;
        }

        if (!$confirmed) {
            $this->warn('Cancelled deleting public SSH key');

            return Command::SUCCESS;
        }

        try {
            $this->io->promptSpin(
                fn () => $this->do->key->deletePublicKey((int) $keyId),
                'Deleting public SSH key...'
            );

            $this->yay('Public SSH key deleted successfully');
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        $this->commandReplay('pro:do:key:delete', [
            'key' => (string) $keyId,
            'force' => true,
            'yes' => $confirmed,
        ]);

        return Command::SUCCESS;
    }

    // ----
    // Helpers
    // ----

    /**
     * Confirm key deletion with type-to-confirm and yes/no prompt.
     *
     * @return bool|null True if confirmed, false if cancelled, null if validation failed
     */
    protected function confirmDeletion(string $keyId, bool $forceSkip): ?bool
    {
        if (!$forceSkip) {
            $typedKeyId = $this->io->promptText(
                label: "Type the key ID '{$keyId}' to confirm deletion:",
                required: true
            );

            if ($typedKeyId !== $keyId) {
                $this->nay('Key ID does not match. Deletion cancelled.');

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
