<?php

declare(strict_types=1);

namespace Deployer\Console\Server;

use Deployer\Contracts\BaseCommand;
use Deployer\DTOs\ServerDTO;
use Deployer\Traits\KeysTrait;
use Deployer\Traits\PlaybooksTrait;
use Deployer\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'server:add',
    description: 'Add a new server to the inventory'
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

        if ($deets === null) {
            return Command::FAILURE;
        }

        [
            'name' => $name,
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'privateKeyPath' => $privateKeyPath,
        ] = $deets;

        // Create server DTO
        $server = $this->serverInfo(new ServerDTO(
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
     * @return array{name: string, host: string, port: int, username: string, privateKeyPath: string}|null
     */
    protected function gatherServerDeets(): ?array
    {
        /** @var string|null $name */
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

        if ($name === null) {
            return null;
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
            fn ($value) => $this->validateServerHost($value)
        );

        if ($host === null) {
            return null;
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
            fn ($value) => $this->validateServerPort($value)
        );

        if ($portString === null) {
            return null;
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
            $this->nay('SSH private key not found.');

            return null;
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
