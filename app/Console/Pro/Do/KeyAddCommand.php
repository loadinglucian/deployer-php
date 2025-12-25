<?php

declare(strict_types=1);

namespace Deployer\Console\Pro\Do;

use Deployer\Contracts\BaseCommand;
use Deployer\Exceptions\ValidationException;
use Deployer\Traits\DoTrait;
use Deployer\Traits\KeysTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'pro:do:key:add',
    description: 'Add a local SSH public key to DigitalOcean'
)]
class KeyAddCommand extends BaseCommand
{
    use DoTrait;
    use KeysTrait;

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Key name in DigitalOcean account')
            ->addOption('public-key-path', null, InputOption::VALUE_REQUIRED, 'SSH public key path');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Add SSH Key to DigitalOcean');

        //
        // Retrieve DigitalOcean account data
        // ----

        if (Command::FAILURE === $this->initializeDoAPI()) {
            return Command::FAILURE;
        }

        //
        // Gather key details
        // ----

        $deets = $this->gatherKeyDeets();

        if (is_int($deets)) {
            return Command::FAILURE;
        }

        [
            'publicKeyPath' => $publicKeyPath,
            'keyName' => $keyName,
        ] = $deets;

        //
        // Upload public key
        // ----

        try {
            $keyId = $this->io->promptSpin(
                fn () => $this->do->key->uploadPublicKey($publicKeyPath, $keyName),
                'Uploading public SSH key...'
            );

            $this->yay("Public SSH key uploaded successfully (ID: {$keyId})");
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        //
        // Show command replay
        // ----

        $this->commandReplay('pro:do:key:add', [
            'public-key-path' => $publicKeyPath,
            'name' => $keyName,
        ]);

        return Command::SUCCESS;
    }

    // ----
    // Helpers
    // ----

    /**
     * Gather key details from user input or CLI options.
     *
     * @return array{publicKeyPath: string, keyName: string}|int
     */
    protected function gatherKeyDeets(): array|int
    {
        try {
            /** @var string $publicKeyPathRaw */
            $publicKeyPathRaw = $this->io->getValidatedOptionOrPrompt(
                'public-key-path',
                fn ($validate) => $this->io->promptText(
                    label: 'Path to SSH public key (leave empty for default ~/.ssh/id_ed25519.pub or ~/.ssh/id_rsa.pub):',
                    default: '',
                    required: false,
                    hint: 'Used when provisioning a server',
                    validate: $validate
                ),
                fn ($value) => $this->validateKeyPathInput($value)
            );

            $publicKeyPath = $this->resolvePublicKeyPath($publicKeyPathRaw);

            if (null === $publicKeyPath) {
                throw new ValidationException('SSH public key not found.');
            }

            $defaultName = 'deployer-key';

            /** @var string $keyName */
            $keyName = $this->io->getValidatedOptionOrPrompt(
                'name',
                fn ($validate) => $this->io->promptText(
                    label: 'Key name:',
                    placeholder: $defaultName,
                    default: $defaultName,
                    required: true,
                    validate: $validate
                ),
                fn ($value) => $this->validateKeyNameInput($value)
            );
        } catch (ValidationException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        return [
            'publicKeyPath' => $publicKeyPath,
            'keyName' => $keyName,
        ];
    }
}
