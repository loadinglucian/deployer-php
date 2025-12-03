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
    name: 'key:add:digitalocean',
    description: 'Add a local SSH public key to DigitalOcean'
)]
class KeyAddDigitalOceanCommand extends BaseCommand
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

        if ($this->initializeDigitalOceanAPI() === Command::FAILURE) {
            return Command::FAILURE;
        }

        //
        // Gather key details
        // ----

        $deets = $this->gatherKeyDeets();

        if ($deets === null) {
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
                fn () => $this->digitalOcean->key->uploadPublicKey($publicKeyPath, $keyName),
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

        $this->commandReplay('key:add:digitalocean', [
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
     * @return array{publicKeyPath: string, keyName: string}|null
     */
    protected function gatherKeyDeets(): ?array
    {
        /** @var string|null $publicKeyPathRaw */
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

        /** @var ?string $publicKeyPath */
        $publicKeyPath = $this->resolvePublicKeyPath($publicKeyPathRaw);

        if ($publicKeyPath === null) {
            $this->nay('SSH public key not found.');
            return null;
        }

        $defaultName = 'deployer-key';

        /** @var string|null $keyName */
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

        if ($keyName === null) {
            return null;
        }

        return [
            'publicKeyPath' => $publicKeyPath,
            'keyName' => $keyName,
        ];
    }
}
