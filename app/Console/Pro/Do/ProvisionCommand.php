<?php

declare(strict_types=1);

namespace Deployer\Console\Pro\Do;

use Deployer\Contracts\BaseCommand;
use Deployer\DTOs\ServerDTO;
use Deployer\Exceptions\ValidationException;
use Deployer\Traits\DoTrait;
use Deployer\Traits\KeysTrait;
use Deployer\Traits\PlaybooksTrait;
use Deployer\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'pro:do:provision',
    description: 'Provision a new DigitalOcean droplet and add it to inventory'
)]
class ProvisionCommand extends BaseCommand
{
    use DoTrait;
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
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Server name for inventory')
            ->addOption('region', null, InputOption::VALUE_REQUIRED, 'DigitalOcean region (e.g., nyc3, sfo3)')
            ->addOption('image', null, InputOption::VALUE_REQUIRED, 'OS image (e.g., ubuntu-22-04-x64)')
            ->addOption('private-key-path', null, InputOption::VALUE_REQUIRED, 'SSH private key path')
            ->addOption('size', null, InputOption::VALUE_REQUIRED, 'Droplet size (e.g., s-1vcpu-1gb)')
            ->addOption('ssh-key-id', null, InputOption::VALUE_REQUIRED, 'SSH key ID')
            ->addOption('vpc-uuid', null, InputOption::VALUE_REQUIRED, 'VPC UUID (default: use default VPC)')
            ->addOption('backups', null, InputOption::VALUE_NEGATABLE, 'Enable backups')
            ->addOption('ipv6', null, InputOption::VALUE_NEGATABLE, 'Enable IPv6')
            ->addOption('monitoring', null, InputOption::VALUE_NEGATABLE, 'Enable monitoring');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Provision DigitalOcean Droplet');

        if (Command::FAILURE === $this->initializeDoAPI()) {
            return Command::FAILURE;
        }

        $accountData = $this->fetchAccountData();

        if (is_int($accountData)) {
            return Command::FAILURE;
        }

        $keys = $this->ensureKeysAvailable($accountData['keys']);

        if (Command::FAILURE === $keys) {
            return Command::FAILURE;
        }

        $deets = $this->gatherProvisioningDeets($accountData);

        if (is_int($deets)) {
            return Command::FAILURE;
        }

        $dropletId = $this->provisionDroplet($deets);

        if (null === $dropletId) {
            return Command::FAILURE;
        }

        $result = $this->configureDroplet($dropletId, $deets);

        if (Command::FAILURE === $result) {
            return Command::FAILURE;
        }

        $this->commandReplay('pro:do:provision', [
            'name' => $deets['name'],
            'region' => $deets['region'],
            'size' => $deets['size'],
            'image' => $deets['image'],
            'ssh-key-id' => $deets['sshKeyId'],
            'private-key-path' => $deets['privateKeyPath'],
            'backups' => $deets['backups'],
            'monitoring' => $deets['monitoring'],
            'ipv6' => $deets['ipv6'],
            'vpc-uuid' => $deets['vpcUuidDisplay'],
        ]);

        return Command::SUCCESS;
    }

    // ----
    // Helpers
    // ----

    /**
     * Fetch DigitalOcean account data.
     *
     * @return array{keys: array<int|string, string>, regions: array<string, string>, sizes: array<string, string>, images: array<string, string>}|int
     */
    protected function fetchAccountData(): array|int
    {
        try {
            return $this->io->promptSpin(
                fn () => [
                    'keys' => $this->do->account->getPublicKeys(),
                    'regions' => $this->do->account->getAvailableRegions(),
                    'sizes' => $this->do->account->getAvailableSizes(),
                    'images' => $this->do->account->getAvailableImages(),
                ],
                'Retrieving account information...'
            );
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Provision the droplet.
     *
     * @param array{name: string, region: string, size: string, image: string, sshKeyId: int, sshKeyIds: array<int, int>, privateKeyPath: string, backups: bool, monitoring: bool, ipv6: bool, vpcUuid: string|null, vpcUuidDisplay: string} $deets
     */
    protected function provisionDroplet(array $deets): ?int
    {
        try {
            $dropletData = $this->io->promptSpin(
                fn () => $this->do->droplet->createDroplet(
                    name: $deets['name'],
                    region: $deets['region'],
                    size: $deets['size'],
                    image: $deets['image'],
                    sshKeys: $deets['sshKeyIds'],
                    backups: $deets['backups'],
                    monitoring: $deets['monitoring'],
                    ipv6: $deets['ipv6'],
                    vpcUuid: $deets['vpcUuid']
                ),
                'Provisioning droplet...'
            );

            $this->yay("Droplet provisioned (ID: {$dropletData['id']})");

            return $dropletData['id'];
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return null;
        }
    }

    /**
     * Configure droplet and add to inventory with automatic rollback on failure.
     *
     * @param array{name: string, region: string, size: string, image: string, sshKeyId: int, sshKeyIds: array<int, int>, privateKeyPath: string, backups: bool, monitoring: bool, ipv6: bool, vpcUuid: string|null, vpcUuidDisplay: string} $deets
     */
    protected function configureDroplet(int $dropletId, array $deets): int
    {
        $provisionSuccess = false;

        try {
            $this->io->promptSpin(
                fn () => $this->do->droplet->waitForDropletReady($dropletId),
                'Waiting for droplet to become active...'
            );

            $this->yay('Droplet is active');

            $ipAddress = $this->do->droplet->getDropletIp($dropletId);

            $server = $this->getServerInfo(new ServerDTO(
                name: $deets['name'],
                host: $ipAddress,
                port: 22,
                username: 'root',
                privateKeyPath: $deets['privateKeyPath'],
                provider: 'digitalocean',
                dropletId: $dropletId
            ));

            if (!is_int($server)) {
                $this->servers->create($server);

                $this->yay('Server added to inventory');

                $this->ul([
                    'Run <|cyan>server:info</> to view server information',
                    'Or run <|cyan>server:install</> to install your new server',
                ]);

                $provisionSuccess = true;
            }
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());
        }

        if (!$provisionSuccess) {
            $this->rollbackDroplet($dropletId);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Rollback droplet on failure.
     */
    protected function rollbackDroplet(int $dropletId): void
    {
        try {
            $this->io->promptSpin(
                fn () => $this->do->droplet->destroyDroplet($dropletId),
                'Rolling back droplet...'
            );

            $this->warn('Rolled back droplet');
        } catch (\Throwable $cleanupError) {
            $this->nay($cleanupError->getMessage());
        }
    }

    /**
     * Gather provisioning details from user input or CLI options.
     *
     * @param array{keys: array<int|string, string>, regions: array<string, string>, sizes: array<string, string>, images: array<string, string>} $accountData
     *
     * @return array{name: string, region: string, size: string, image: string, sshKeyId: int, sshKeyIds: array<int, int>, privateKeyPath: string, backups: bool, monitoring: bool, ipv6: bool, vpcUuid: string|null, vpcUuidDisplay: string}|int
     */
    protected function gatherProvisioningDeets(array $accountData): array|int
    {
        try {
            $this->validateAccountDataAvailability($accountData);

            $coreDeets = $this->gatherCoreProvisioningDeets($accountData);

            if (is_int($coreDeets)) {
                return Command::FAILURE;
            }

            $optionalDeets = $this->gatherOptionalDeets($coreDeets['region']);

            if (is_int($optionalDeets)) {
                return Command::FAILURE;
            }
        } catch (ValidationException|\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        return array_merge($coreDeets, $optionalDeets);
    }

    /**
     * Validate that required account data is available.
     *
     * @param array{keys: array<int|string, string>, regions: array<string, string>, sizes: array<string, string>, images: array<string, string>} $accountData
     *
     * @throws ValidationException
     */
    protected function validateAccountDataAvailability(array $accountData): void
    {
        if (0 === count($accountData['regions'])) {
            throw new ValidationException('No regions available in your DigitalOcean account');
        }

        if (0 === count($accountData['sizes'])) {
            throw new ValidationException('No droplet sizes available');
        }

        if (0 === count($accountData['images'])) {
            throw new ValidationException('No supported OS images available');
        }
    }

    /**
     * Gather core provisioning details (name, region, size, image, SSH key, private key path).
     *
     * @param array{keys: array<int|string, string>, regions: array<string, string>, sizes: array<string, string>, images: array<string, string>} $accountData
     *
     * @return array{name: string, region: string, size: string, image: string, sshKeyId: int, sshKeyIds: array<int, int>, privateKeyPath: string}|int
     */
    protected function gatherCoreProvisioningDeets(array $accountData): array|int
    {
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

        /** @var string $region */
        $region = $this->io->getValidatedOptionOrPrompt(
            'region',
            fn ($validate) => $this->io->promptSelect(
                label: 'Select region:',
                options: $accountData['regions'],
                hint: 'Choose the datacenter location',
                default: '',
                scroll: 15,
                validate: $validate
            ),
            fn ($value) => $this->validateDoRegion($value, $accountData['regions'])
        );

        /** @var string $size */
        $size = $this->io->getValidatedOptionOrPrompt(
            'size',
            fn ($validate) => $this->io->promptSelect(
                label: 'Select droplet size:',
                options: $accountData['sizes'],
                hint: 'Choose CPU, RAM, and storage',
                default: '',
                scroll: 15,
                validate: $validate
            ),
            fn ($value) => $this->validateDoDropletSize($value, $accountData['sizes'])
        );

        /** @var string $image */
        $image = $this->io->getValidatedOptionOrPrompt(
            'image',
            fn ($validate) => $this->io->promptSelect(
                label: 'Select OS image:',
                options: $accountData['images'],
                hint: 'Supported Linux distributions',
                default: 'ubuntu-24-04-x64',
                scroll: 15,
                validate: $validate
            ),
            fn ($value) => $this->validateDoDropletImage($value, $accountData['images'])
        );

        /** @var array<int, string> $keys */
        $keys = $accountData['keys'];

        /** @var int|string $selectedKey */
        $selectedKey = $this->io->getValidatedOptionOrPrompt(
            'ssh-key-id',
            fn ($validate) => $this->io->promptSelect(
                label: 'Select public SSH key for droplet access:',
                options: $accountData['keys'],
                validate: $validate
            ),
            fn (mixed $value): ?string => $this->validateDoSSHKey($value, $keys)
        );

        $sshKeyId = is_int($selectedKey) ? $selectedKey : (int) $selectedKey;

        /** @var array<int, int> $sshKeyIds */
        $sshKeyIds = [$sshKeyId];

        $privateKeyPath = $this->promptPrivateKeyPath();

        return [
            'name' => $name,
            'region' => $region,
            'size' => $size,
            'image' => $image,
            'sshKeyId' => $sshKeyId,
            'sshKeyIds' => $sshKeyIds,
            'privateKeyPath' => $privateKeyPath,
        ];
    }

    /**
     * Gather optional parameters (backups, monitoring, ipv6, VPC).
     *
     * @return array{backups: bool, monitoring: bool, ipv6: bool, vpcUuid: string|null, vpcUuidDisplay: string}|int
     */
    protected function gatherOptionalDeets(string $region): array|int
    {
        $backups = $this->io->getBooleanOptionOrPrompt(
            'backups',
            fn () => $this->io->promptConfirm(
                label: 'Enable automatic backups?',
                default: false,
                hint: 'Costs extra if enabled'
            )
        );

        $monitoring = $this->io->getBooleanOptionOrPrompt(
            'monitoring',
            fn () => $this->io->promptConfirm(
                label: 'Enable monitoring?',
                default: true,
                hint: 'Free - shows CPU, memory, disk metrics'
            )
        );

        $ipv6 = $this->io->getBooleanOptionOrPrompt(
            'ipv6',
            fn () => $this->io->promptConfirm(
                label: 'Enable IPv6?',
                default: true,
                hint: 'Free - provides an IPv6 address'
            )
        );

        $availableVpcs = $this->do->account->getUserVpcs($region);

        if (0 === count($availableVpcs)) {
            throw new \RuntimeException("No VPCs found in region '{$region}'");
        }

        /** @var string $vpcUuid */
        $vpcUuid = $this->io->getValidatedOptionOrPrompt(
            'vpc-uuid',
            fn ($validate) => $this->io->promptSelect(
                label: 'Select VPC:',
                options: $availableVpcs,
                hint: 'Virtual Private Cloud for network isolation',
                validate: $validate
            ),
            fn ($value) => $this->validateDoVPCUUID($value, $availableVpcs)
        );

        $vpcUuidForApi = ('default' === $vpcUuid) ? null : $vpcUuid;

        return [
            'backups' => $backups,
            'monitoring' => $monitoring,
            'ipv6' => $ipv6,
            'vpcUuid' => $vpcUuidForApi,
            'vpcUuidDisplay' => $vpcUuid,
        ];
    }
}
