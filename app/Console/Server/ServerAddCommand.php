<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Server;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\DTOs\ServerDTO;
use DeployerPHP\Exceptions\ValidationException;
use DeployerPHP\Traits\KeysTrait;
use DeployerPHP\Traits\PlaybooksTrait;
use DeployerPHP\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'server:add',
    description: 'Add a new server to inventory'
)]
class ServerAddCommand extends BaseCommand
{
    use KeysTrait;
    use PlaybooksTrait;
    use ServersTrait;

    // ----
    // Configuration
    // ----

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

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Add New Server');

        //
        // Gather server details
        // ----

        $deets = $this->gatherServerDeets();

        if (is_int($deets)) {
            return Command::FAILURE;
        }

        [
            'name' => $name,
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'privateKeyPath' => $privateKeyPath,
        ] = $deets;

        // Create server DTO with info
        $server = $this->getServerInfo(new ServerDTO(
            name: $name,
            host: $host,
            port: $port,
            username: $username,
            privateKeyPath: $privateKeyPath
        ));

        if (is_int($server)) {
            return Command::FAILURE;
        }

        //
        // Add to inventory
        // ----

        try {
            $this->servers->create($server);
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        $this->yay('Server added to inventory');

        $this->ul([
            'Run <|cyan>server:info</> to view server information',
            'Or run <|cyan>server:install</> to install your new server',
        ]);

        //
        // Show command replay
        // ----

        $this->commandReplay('server:add', [
            'name' => $name,
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'private-key-path' => $privateKeyPath,
        ]);

        return Command::SUCCESS;
    }

    // ----
    // Helpers
    // ----

    /**
     * Gather server details from user input or CLI options.
     *
     * @return array{name: string, host: string, port: int, username: string, privateKeyPath: string}|int
     */
    protected function gatherServerDeets(): array|int
    {
        try {
            /** @var string $name */
            $name = $this->io->getValidatedOptionOrPrompt(
                'name',
                fn ($validate) => $this->io->promptText(
                    label: 'Server name:',
                    placeholder: 'web1',
                    required: true,
                    validate: $validate
                ),
                fn ($value) => $this->validateServerName($value)
            );

            /** @var string $host */
            $host = $this->io->getValidatedOptionOrPrompt(
                'host',
                fn ($validate) => $this->io->promptText(
                    label: 'Host/IP address:',
                    placeholder: '192.168.1.100',
                    required: true,
                    validate: $validate
                ),
                fn ($value) => $this->validateServerHost($value)
            );

            /** @var string $portString */
            $portString = $this->io->getValidatedOptionOrPrompt(
                'port',
                fn ($validate) => $this->io->promptText(
                    label: 'SSH port:',
                    default: '22',
                    required: true,
                    validate: $validate
                ),
                fn ($value) => $this->validateServerPort($value)
            );

            $port = (int) $portString;

            /** @var string $username */
            $username = $this->io->getValidatedOptionOrPrompt(
                'username',
                fn ($validate): string => $this->io->promptText(
                    label: 'SSH username:',
                    default: 'root',
                    required: true,
                    validate: $validate
                ),
                fn ($value) => $this->validateUsernameInput($value)
            );

            $privateKeyPath = $this->promptPrivateKeyPath();
        } catch (ValidationException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        return [
            'name' => $name,
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'privateKeyPath' => $privateKeyPath,
        ];
    }
}
