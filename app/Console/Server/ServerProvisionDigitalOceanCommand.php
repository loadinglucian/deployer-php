<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Console\Server;

use Bigpixelrocket\DeployerPHP\Contracts\BaseCommand;
use Bigpixelrocket\DeployerPHP\DTOs\ServerDTO;
use Bigpixelrocket\DeployerPHP\Traits\DigitalOceanCommandTrait;
use Bigpixelrocket\DeployerPHP\Traits\DigitalOceanValidationTrait;
use Bigpixelrocket\DeployerPHP\Traits\KeyHelpersTrait;
use Bigpixelrocket\DeployerPHP\Traits\KeyValidationTrait;
use Bigpixelrocket\DeployerPHP\Traits\ServerHelpersTrait;
use Bigpixelrocket\DeployerPHP\Traits\ServerValidationTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'server:provision:digitalocean',
    description: 'Provision a new DigitalOcean droplet and add it to inventory'
)]
class ServerProvisionDigitalOceanCommand extends BaseCommand
{
    use DigitalOceanCommandTrait;
    use DigitalOceanValidationTrait;
    use ServerHelpersTrait;
    use ServerValidationTrait;
    use KeyHelpersTrait;
    use KeyValidationTrait;

    //
    // Configuration
    // -------------------------------------------------------------------------------

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Server name for inventory')
            ->addOption('region', null, InputOption::VALUE_REQUIRED, 'DigitalOcean region (e.g., nyc3, sfo3)')
            ->addOption('image', null, InputOption::VALUE_REQUIRED, 'OS image (e.g., ubuntu-22-04-x64)')
            ->addOption('private-key-path', null, InputOption::VALUE_REQUIRED, 'SSH private key path')
            ->addOption('size', null, InputOption::VALUE_REQUIRED, 'Droplet size (e.g., s-1vcpu-1gb)')
            ->addOption('ssh-key-id', null, InputOption::VALUE_REQUIRED, 'SSH key ID')
            ->addOption('vpc-uuid', null, InputOption::VALUE_REQUIRED, 'VPC UUID (default: use default VPC)')
            ->addOption('backups', null, InputOption::VALUE_NONE, 'Enable backups')
            ->addOption('ipv6', null, InputOption::VALUE_NONE, 'Enable IPv6')
            ->addOption('monitoring', null, InputOption::VALUE_NONE, 'Enable monitoring');
    }

    //
    // Execution
    // -------------------------------------------------------------------------------

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->io->hr();
        $this->io->h1('Provision DigitalOcean Droplet');

        if ($this->initializeDigitalOceanAPI() === Command::FAILURE) {
            return Command::FAILURE;
        }

        //
        // Retrieve DigitalOcean data

        $accountData = $this->io->promptSpin(
            fn () => [
                'keys' => $this->digitalOcean->account->getUserSshKeys(),
                'regions' => $this->digitalOcean->account->getAvailableRegions(),
                'sizes' => $this->digitalOcean->account->getAvailableSizes(),
                'images' => $this->digitalOcean->account->getAvailableImages(),
            ],
            'Retrieving account information...'
        );

        if (count($accountData['keys']) === 0) {
            $this->io->warning('No SSH keys found in your DigitalOcean account');
            $this->io->writeln([
                '',
                'You must add at least one SSH key before provisioning a server.',
                'Run <fg=cyan>key:add:digitalocean</> to add an SSH key.',
                '',
            ]);

            return Command::FAILURE;
        }

        //
        // Gather droplet configuration

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

        /** @var string|null $region */
        $region = $this->io->getValidatedOptionOrPrompt(
            'region',
            fn ($validate) => $this->io->promptSelect(
                label: 'Select region:',
                options: $accountData['regions'],
                hint: 'Choose the datacenter location'
            ),
            fn ($value) => $this->validateRegionInput($value, $accountData['regions'])
        );

        if ($region === null) {
            return Command::FAILURE;
        }

        /** @var string|null $size */
        $size = $this->io->getValidatedOptionOrPrompt(
            'size',
            fn ($validate) => $this->io->promptSelect(
                label: 'Select droplet size:',
                options: $accountData['sizes'],
                hint: 'Choose CPU, RAM, and storage'
            ),
            fn ($value) => $this->validateSizeInput($value, $accountData['sizes'])
        );

        if ($size === null) {
            return Command::FAILURE;
        }

        /** @var string|null $image */
        $image = $this->io->getValidatedOptionOrPrompt(
            'image',
            fn ($validate) => $this->io->promptSelect(
                label: 'Select OS image:',
                options: $accountData['images'],
                hint: 'Ubuntu and Debian only'
            ),
            fn ($value) => $this->validateImageInput($value, $accountData['images'])
        );

        if ($image === null) {
            return Command::FAILURE;
        }

        //
        // Select SSH key

        /** @var int|string|null $selectedKey */
        $selectedKey = $this->io->getValidatedOptionOrPrompt(
            'ssh-key-id',
            fn ($validate) => $this->io->promptSelect(
                label: 'Select SSH key for droplet access:',
                options: $accountData['keys'],
                validate: $validate
            ),
            fn (mixed $value): ?string => $this->validateSshKeyInput($value, $accountData['keys'])
        );

        if ($selectedKey === null) {
            return Command::FAILURE;
        }

        // Convert to integer if string was provided
        $sshKeyId = is_int($selectedKey) ? $selectedKey : (int) $selectedKey;

        // API expects array of SSH key IDs
        /** @var array<int, int> $sshKeyIds */
        $sshKeyIds = [$sshKeyId];

        //
        // Prompt for local private key path

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
        // Gather optional parameters

        /** @var bool $backups */
        $backups = $this->io->getOptionOrPrompt(
            'backups',
            fn () => $this->io->promptConfirm(
                label: 'Enable automatic backups?',
                default: false,
                hint: 'Costs extra if enabled'
            )
        );

        /** @var bool $monitoring */
        $monitoring = $this->io->getOptionOrPrompt(
            'monitoring',
            fn () => $this->io->promptConfirm(
                label: 'Enable monitoring?',
                default: true,
                hint: 'Free - shows CPU, memory, disk metrics'
            )
        );

        /** @var bool $ipv6 */
        $ipv6 = $this->io->getOptionOrPrompt(
            'ipv6',
            fn () => $this->io->promptConfirm(
                label: 'Enable IPv6?',
                default: true,
                hint: 'Free - provides an IPv6 address'
            )
        );

        /** @var string|null $vpcUuid */
        $vpcUuid = $this->io->getValidatedOptionOrPrompt(
            'vpc-uuid',
            fn ($validate) => $this->io->promptSelect(
                label: 'Select VPC:',
                options: $this->digitalOcean->account->getUserVpcs($region),
                hint: 'Virtual Private Cloud for network isolation'
            ),
            fn ($value) => $this->validateVpcUuidInput($value)
        );

        if ($vpcUuid === null) {
            return Command::FAILURE;
        }

        // Convert "default" to null for API
        if ($vpcUuid === 'default') {
            $vpcUuid = null;
        }

        //
        // Display provisioning summary

        $this->io->hr();

        $this->io->writeln([
            "  Name:       <fg=gray>{$name}</>",
            "  Region:     <fg=gray>{$region}</>",
            "  Size:       <fg=gray>{$size}</>",
            "  Image:      <fg=gray>{$image}</>",
            "  SSH Key:    <fg=gray>{$accountData['keys'][$sshKeyId]}</>",
            '  Backups:    <fg=gray>' . ($backups ? 'enabled' : 'disabled') . '</>',
            '  Monitoring: <fg=gray>' . ($monitoring ? 'enabled' : 'disabled') . '</>',
            '  IPv6:       <fg=gray>' . ($ipv6 ? 'enabled' : 'disabled') . '</>',
            '  VPC:        <fg=gray>' . ($vpcUuid ?? 'default') . '</>',
            '',
        ]);

        //
        // Create droplet

        try {
            $dropletData = $this->io->promptSpin(
                fn () => $this->digitalOcean->droplet->createDroplet(
                    name: $name,
                    region: $region,
                    size: $size,
                    image: $image,
                    sshKeys: $sshKeyIds,
                    backups: $backups,
                    monitoring: $monitoring,
                    ipv6: $ipv6,
                    vpcUuid: $vpcUuid
                ),
                'Creating droplet...'
            );

            $dropletId = $dropletData['id'];
            $this->io->success("Droplet created (ID: {$dropletId})");
        } catch (\RuntimeException $e) {
            $this->io->error('Failed to create droplet: ' . $e->getMessage());

            return Command::FAILURE;
        }

        //
        // Wait for droplet to become active

        $this->io->writeln('');

        try {
            $this->io->promptSpin(
                fn () => $this->digitalOcean->droplet->waitForDropletReady($dropletId),
                'Waiting for droplet to become active...'
            );

            $this->io->success('Droplet is now active');
        } catch (\RuntimeException $e) {
            $this->io->error($e->getMessage());
            $this->rollbackDroplet($dropletId);

            return Command::FAILURE;
        }

        //
        // Get droplet IP address

        try {
            $ipAddress = $this->digitalOcean->droplet->getDropletIp($dropletId);
            $this->io->writeln('');
            $this->io->writeln("  Public IP: <fg=gray>{$ipAddress}</>");
        } catch (\RuntimeException $e) {
            $this->io->error('Failed to retrieve IP address: ' . $e->getMessage());
            $this->rollbackDroplet($dropletId);

            return Command::FAILURE;
        }

        //
        // Add to inventory

        $server = new ServerDTO(
            name: $name,
            host: $ipAddress,
            port: 22,
            username: 'root',
            privateKeyPath: $privateKeyPath,
            provider: 'digitalocean',
            dropletId: $dropletId
        );

        try {
            $this->servers->create($server);
        } catch (\RuntimeException $e) {
            $this->io->error('Failed to add server to inventory: ' . $e->getMessage());
            $this->rollbackDroplet($dropletId);

            return Command::FAILURE;
        }

        $this->io->writeln('');
        $this->io->success('Server added to inventory');
        $this->io->writeln('');

        //
        // Display server details
        // -------------------------------------------------------------------------------

        $this->displayServerDeets($server);

        //
        // Show command hint
        // -------------------------------------------------------------------------------

        $this->io->showCommandHint('server:provision:digitalocean', [
            'name' => $name,
            'region' => $region,
            'size' => $size,
            'image' => $image,
            'ssh-key-id' => $sshKeyId,
            'backups' => $backups,
            'monitoring' => $monitoring,
            'ipv6' => $ipv6,
            'vpc-uuid' => $vpcUuid,
        ]);

        return Command::SUCCESS;
    }

    //
    // Rollback
    // -------------------------------------------------------------------------------

    /**
     * Destroy a droplet after failed provisioning.
     *
     * @param int $dropletId The droplet ID to destroy
     */
    private function rollbackDroplet(int $dropletId): void
    {
        try {
            $this->io->writeln('');
            $this->io->promptSpin(
                fn () => $this->digitalOcean->droplet->destroyDroplet($dropletId),
                'Destroying droplet...'
            );

            $this->io->warning('Rolled back provisioning of droplet.');
        } catch (\Throwable $cleanupError) {
            $this->io->warning($cleanupError->getMessage());
        }
    }
}
