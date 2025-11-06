<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Console\Server;

use Bigpixelrocket\DeployerPHP\Contracts\BaseCommand;
use Bigpixelrocket\DeployerPHP\DTOs\ServerDTO;
use Bigpixelrocket\DeployerPHP\Traits\DigitalOceanTrait;
use Bigpixelrocket\DeployerPHP\Traits\KeysTrait;
use Bigpixelrocket\DeployerPHP\Traits\ServersTrait;
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
    use DigitalOceanTrait;
    use ServersTrait;
    use KeysTrait;

    // -------------------------------------------------------------------------------
    //
    // Configuration
    //
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

    // -------------------------------------------------------------------------------
    //
    // Execution
    //
    // -------------------------------------------------------------------------------

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->heading('Provision DigitalOcean Droplet');

        //
        // Retrieve DigitalOcean account data
        // -------------------------------------------------------------------------------

        if ($this->initializeDigitalOceanAPI() === Command::FAILURE) {
            return Command::FAILURE;
        }

        $accountData = $this->io->promptSpin(
            fn () => [
                'keys' => $this->digitalOcean->account->getPublicKeys(),
                'regions' => $this->digitalOcean->account->getAvailableRegions(),
                'sizes' => $this->digitalOcean->account->getAvailableSizes(),
                'images' => $this->digitalOcean->account->getAvailableImages(),
            ],
            'Retrieving account information...'
        );

        $keys = $this->ensureKeysAvailable($accountData['keys']);

        if ($keys === Command::FAILURE) {
            return Command::FAILURE;
        }

        //
        // Gather provisioning details
        // -------------------------------------------------------------------------------

        $deets = $this->gatherProvisioningDeets($accountData);

        if ($deets === null) {
            return Command::FAILURE;
        }

        [
            'name' => $name,
            'region' => $region,
            'size' => $size,
            'image' => $image,
            'sshKeyId' => $sshKeyId,
            'sshKeyIds' => $sshKeyIds,
            'privateKeyPath' => $privateKeyPath,
            'backups' => $backups,
            'monitoring' => $monitoring,
            'ipv6' => $ipv6,
            'vpcUuid' => $vpcUuid,
        ] = $deets;

        //
        // Provision droplet
        // -------------------------------------------------------------------------------

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
                'Provisioning droplet...'
            );

            $dropletId = $dropletData['id'];
            $this->yay("Droplet provisioned (ID: {$dropletId})");
        } catch (\RuntimeException $e) {
            $this->nay('Failed to provision droplet: ' . $e->getMessage());

            return Command::FAILURE;
        }

        //
        // Wait for droplet to become active
        // -------------------------------------------------------------------------------

        try {
            $this->io->promptSpin(
                fn () => $this->digitalOcean->droplet->waitForDropletReady($dropletId),
                'Waiting for droplet to become active...'
            );

            $this->yay('Droplet is active');
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());
            $this->rollbackDroplet($dropletId);

            return Command::FAILURE;
        }

        //
        // Get droplet IP address & display server details
        // -------------------------------------------------------------------------------

        try {
            $ipAddress = $this->digitalOcean->droplet->getDropletIp($dropletId);
        } catch (\RuntimeException $e) {
            $this->nay('Failed to get droplet IP address: ' . $e->getMessage());
            $this->rollbackDroplet($dropletId);

            return Command::FAILURE;
        }

        $server = new ServerDTO(
            name: $name,
            host: $ipAddress,
            port: 22,
            username: 'root',
            privateKeyPath: $privateKeyPath,
            provider: 'digitalocean',
            dropletId: $dropletId
        );

        $this->displayServerDeets($server);

        //
        // Verify SSH connection & add to inventory
        // -------------------------------------------------------------------------------

        $this->verifySSHConnection($server); // SSH failure is not a blocker

        try {
            $this->servers->create($server);
        } catch (\RuntimeException $e) {
            $this->nay('Failed to add server to inventory: ' . $e->getMessage());
            $this->rollbackDroplet($dropletId);

            return Command::FAILURE;
        }

        $this->yay('Server added to inventory');

        //
        // Show command replay
        // -------------------------------------------------------------------------------

        $this->showCommandReplay('server:provision:digitalocean', [
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

    // -------------------------------------------------------------------------------
    //
    // Helpers
    //
    // -------------------------------------------------------------------------------

    /**
     * Gather provisioning details from user input or CLI options.
     *
     * @param array{keys: array<int|string, string>, regions: array<string, string>, sizes: array<string, string>, images: array<string, string>} $accountData
     * @return array{name: string, region: string, size: string, image: string, sshKeyId: int, sshKeyIds: array<int, int>, privateKeyPath: string, backups: bool, monitoring: bool, ipv6: bool, vpcUuid: string|null}|null
     */
    protected function gatherProvisioningDeets(array $accountData): ?array
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

        /** @var string|null $region */
        $region = $this->io->getValidatedOptionOrPrompt(
            'region',
            fn ($validate) => $this->io->promptSelect(
                label: 'Select region:',
                options: $accountData['regions'],
                hint: 'Choose the datacenter location'
            ),
            fn ($value) => $this->validateDigitalOceanRegion($value, $accountData['regions'])
        );

        if ($region === null) {
            return null;
        }

        /** @var string|null $size */
        $size = $this->io->getValidatedOptionOrPrompt(
            'size',
            fn ($validate) => $this->io->promptSelect(
                label: 'Select droplet size:',
                options: $accountData['sizes'],
                hint: 'Choose CPU, RAM, and storage'
            ),
            fn ($value) => $this->validateDigitalOceanDropletSize($value, $accountData['sizes'])
        );

        if ($size === null) {
            return null;
        }

        /** @var string|null $image */
        $image = $this->io->getValidatedOptionOrPrompt(
            'image',
            fn ($validate) => $this->io->promptSelect(
                label: 'Select OS image:',
                options: $accountData['images'],
                hint: 'Supported Linux distributions'
            ),
            fn ($value) => $this->validateDigitalOceanDropletImage($value, $accountData['images'])
        );

        if ($image === null) {
            return null;
        }

        //
        // Select SSH key

        /** @var array<int, string> $keys */
        $keys = $accountData['keys'];

        /** @var int|string|null $selectedKey */
        $selectedKey = $this->io->getValidatedOptionOrPrompt(
            'ssh-key-id',
            fn ($validate) => $this->io->promptSelect(
                label: 'Select public SSH key for droplet access:',
                options: $accountData['keys'],
                validate: $validate
            ),
            fn (mixed $value): ?string => $this->validateDigitalOceanSSHKey($value, $keys)
        );

        if ($selectedKey === null) {
            return null;
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
            $this->nay('SSH private key not found.');

            return null;
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
            fn ($value) => $this->validateDigitalOceanVPCUUID($value)
        );

        if ($vpcUuid === null) {
            return null;
        }

        // Convert "default" to null for API
        if ($vpcUuid === 'default') {
            $vpcUuid = null;
        }

        return [
            'name' => $name,
            'region' => $region,
            'size' => $size,
            'image' => $image,
            'sshKeyId' => $sshKeyId,
            'sshKeyIds' => $sshKeyIds,
            'privateKeyPath' => $privateKeyPath,
            'backups' => $backups,
            'monitoring' => $monitoring,
            'ipv6' => $ipv6,
            'vpcUuid' => $vpcUuid,
        ];
    }

    /**
     * Destroy a droplet after failed provisioning.
     *
     * @param int $dropletId The droplet ID to destroy
     */
    protected function rollbackDroplet(int $dropletId): void
    {
        try {
            $this->io->promptSpin(
                fn () => $this->digitalOcean->droplet->destroyDroplet($dropletId),
                'Rolling back droplet...'
            );

            $this->io->warning('Rolled back droplet');
        } catch (\Throwable $cleanupError) {
            $this->io->warning($cleanupError->getMessage());
        }
    }
}
