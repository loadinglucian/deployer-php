<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Console\Server;

use Bigpixelrocket\DeployerPHP\Contracts\BaseCommand;
use Bigpixelrocket\DeployerPHP\DTOs\ServerDTO;
use Bigpixelrocket\DeployerPHP\Traits\KeyHelpersTrait;
use Bigpixelrocket\DeployerPHP\Traits\KeyValidationTrait;
use Bigpixelrocket\DeployerPHP\Traits\ServerHelpersTrait;
use Bigpixelrocket\DeployerPHP\Traits\ServerValidationTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:add', description: 'Add a new server to the inventory')]
class ServerAddCommand extends BaseCommand
{
    use KeyHelpersTrait;
    use KeyValidationTrait;
    use ServerHelpersTrait;
    use ServerValidationTrait;

    //
    // Configuration
    // -------------------------------------------------------------------------------

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Server name')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host/IP address')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'SSH port (default: 22)')
            ->addOption('private-key-path', null, InputOption::VALUE_REQUIRED, 'SSH private key path')
            ->addOption('username', null, InputOption::VALUE_REQUIRED, 'SSH username (default: root)');
    }

    //
    // Execution
    // -------------------------------------------------------------------------------

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->io->hr();
        $this->io->h1('Add New Server');

        //
        // Gather server details

        /** @var string|null $name */
        $name = $this->io->getValidatedOptionOrPrompt(
            'name',
            fn ($validate) => $this->io->promptText(
                label: 'Server name:',
                placeholder: 'web1',
                required: true,
                validate: $validate
            ),
            fn ($value) => $this->validateNameInput($value)
        );

        if ($name === null) {
            return Command::FAILURE;
        }

        /** @var string|null $host */
        $host = $this->io->getValidatedOptionOrPrompt(
            'host',
            fn ($validate) => $this->io->promptText(
                label: 'Host/IP address:',
                placeholder: '192.168.1.100',
                required: true,
                validate: $validate
            ),
            fn ($value) => $this->validateHostInput($value)
        );

        if ($host === null) {
            return Command::FAILURE;
        }

        /** @var string|null $portString */
        $portString = $this->io->getValidatedOptionOrPrompt(
            'port',
            fn ($validate) => $this->io->promptText(
                label: 'SSH port:',
                default: '22',
                required: true,
                validate: $validate
            ),
            fn ($value) => $this->validatePortInput($value)
        );

        if ($portString === null) {
            return Command::FAILURE;
        }

        $port = (int) $portString;

        /** @var string $username */
        $username = $this->io->getOptionOrPrompt(
            'username',
            fn (): string => $this->io->promptText(
                label: 'SSH username:',
                default: 'root',
                required: true
            )
        );

        /** @var string $privateKeyPathRaw */
        $privateKeyPathRaw = $this->io->getOptionOrPrompt(
            'private-key-path',
            fn (): string => $this->io->promptText(
                label: 'Path to SSH private key (leave empty for default ~/.ssh/id_ed25519 or ~/.ssh/id_rsa):',
                default: '',
                required: false,
                hint: 'Used to connect to the server'
            )
        );

        /** @var ?string $privateKeyPath */
        $privateKeyPath = $this->resolvePrivateKeyPath($privateKeyPathRaw);

        if ($privateKeyPath === null) {
            $this->io->error('SSH private key not found.');
            $this->io->writeln('');

            return Command::FAILURE;
        }

        //
        // Create DTO and display server info

        $server = new ServerDTO(
            name: $name,
            host: $host,
            port: $port,
            username: $username,
            privateKeyPath: $privateKeyPath
        );

        $this->io->hr();

        $this->displayServerDeets($server);

        //
        // Save to repository

        try {
            $this->servers->create($server);
        } catch (\RuntimeException $e) {
            $this->io->error('Failed to add server: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $this->io->success('Server added successfully');
        $this->io->writeln('');

        //
        // Show command hint

        $this->io->showCommandHint('server:add', [
            'name' => $name,
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'private-key-path' => $privateKeyPath,
        ]);

        return Command::SUCCESS;
    }

}
