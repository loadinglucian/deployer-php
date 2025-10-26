<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Console\Key;

use Bigpixelrocket\DeployerPHP\Contracts\BaseCommand;
use Bigpixelrocket\DeployerPHP\Traits\DigitalOceanCommandTrait;
use Bigpixelrocket\DeployerPHP\Traits\KeyHelpersTrait;
use Bigpixelrocket\DeployerPHP\Traits\KeyValidationTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Add a local SSH public key to the user's DigitalOcean account,
 * making it available for droplet provisioning.
 */
#[AsCommand(
    name: 'key:add:digitalocean',
    description: 'Add a local SSH public key to DigitalOcean'
)]
class KeyAddDigitalOceanCommand extends BaseCommand
{
    use DigitalOceanCommandTrait;
    use KeyHelpersTrait;
    use KeyValidationTrait;

    //
    // Configuration
    // -------------------------------------------------------------------------------

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Key name in DigitalOcean account')
            ->addOption('public-key-path', null, InputOption::VALUE_REQUIRED, 'SSH public key path');
    }

    //
    // Execution
    // -------------------------------------------------------------------------------

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->io->hr();
        $this->io->h1('Add SSH Key to DigitalOcean');

        if ($this->initializeDigitalOceanAPI() === Command::FAILURE) {
            return Command::FAILURE;
        }

        //
        // Gather key details

        /** @var string|null $keyPath */
        $keyPath = $this->io->getValidatedOptionOrPrompt(
            'public-key-path',
            fn ($validate) => $this->io->promptText(
                label: 'Path to SSH public key:',
                placeholder: '~/.ssh/id_ed25519.pub',
                required: true,
                validate: $validate
            ),
            fn ($value) => $this->validateKeyPathInput($value)
        );

        if ($keyPath === null) {
            return Command::FAILURE;
        }

        // Expand tilde to home directory
        $keyPath = $this->expandKeyPath($keyPath);

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
            return Command::FAILURE;
        }

        //
        // Upload SSH key

        try {
            $keyId = $this->io->promptSpin(
                fn () => $this->digitalOcean->key->uploadKey($keyPath, $keyName),
                'Uploading SSH key...'
            );

            $this->io->success("SSH key uploaded successfully (ID: {$keyId})");
            $this->io->writeln('');
        } catch (\RuntimeException $e) {
            $this->io->error('Failed to upload SSH key: ' . $e->getMessage());
            $this->io->writeln('');

            return Command::FAILURE;
        }

        //
        // Show command hint

        $this->io->showCommandHint('key:add:digitalocean', [
            'public-key-path' => $keyPath,
            'name' => $keyName,
        ]);

        return Command::SUCCESS;
    }
}
