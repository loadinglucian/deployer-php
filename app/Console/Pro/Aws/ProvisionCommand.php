<?php

declare(strict_types=1);

namespace Deployer\Console\Pro\Aws;

use Deployer\Contracts\BaseCommand;
use Deployer\DTOs\ServerDTO;
use Deployer\Exceptions\ValidationException;
use Deployer\Traits\AwsTrait;
use Deployer\Traits\KeysTrait;
use Deployer\Traits\PlaybooksTrait;
use Deployer\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'pro:aws:provision',
    description: 'Provision a new AWS EC2 instance and add it to inventory'
)]
class ProvisionCommand extends BaseCommand
{
    use AwsTrait;
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
            ->addOption('instance-type', null, InputOption::VALUE_REQUIRED, 'Instance type (e.g., t3.micro)')
            ->addOption('ami', null, InputOption::VALUE_REQUIRED, 'AMI ID')
            ->addOption('key-pair', null, InputOption::VALUE_REQUIRED, 'AWS key pair name')
            ->addOption('private-key-path', null, InputOption::VALUE_REQUIRED, 'SSH private key path')
            ->addOption('vpc', null, InputOption::VALUE_REQUIRED, 'VPC ID')
            ->addOption('subnet', null, InputOption::VALUE_REQUIRED, 'Subnet ID')
            ->addOption('public-ip', null, InputOption::VALUE_NEGATABLE, 'Assign public IP')
            ->addOption('monitoring', null, InputOption::VALUE_NEGATABLE, 'Enable detailed monitoring');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Provision AWS EC2 Instance');

        if (Command::FAILURE === $this->initializeAwsAPI()) {
            return Command::FAILURE;
        }

        $accountData = $this->fetchAccountData();

        if (is_int($accountData)) {
            return Command::FAILURE;
        }

        $keys = $this->ensureAwsKeysAvailable($accountData['keys']);

        if (is_int($keys)) {
            return Command::FAILURE;
        }

        $deets = $this->gatherProvisioningDeets($accountData);

        if (is_int($deets)) {
            return Command::FAILURE;
        }

        $securityGroupId = $this->ensureSecurityGroup($deets['vpcId']);

        if (is_int($securityGroupId)) {
            return Command::FAILURE;
        }

        $instanceId = $this->provisionInstance($deets, $securityGroupId);

        if (is_int($instanceId)) {
            return Command::FAILURE;
        }

        $result = $this->configureInstance($instanceId, $deets);

        if (Command::FAILURE === $result) {
            return Command::FAILURE;
        }

        $this->commandReplay('pro:aws:provision', [
            'name' => $deets['name'],
            'instance-type' => $deets['instanceType'],
            'ami' => $deets['ami'],
            'key-pair' => $deets['keyPair'],
            'private-key-path' => $deets['privateKeyPath'],
            'vpc' => $deets['vpcId'],
            'subnet' => $deets['subnetId'],
            'public-ip' => $deets['publicIp'],
            'monitoring' => $deets['monitoring'],
        ]);

        return Command::SUCCESS;
    }

    // ----
    // Helpers
    // ----

    /**
     * Fetch AWS account data.
     *
     * @return array{instanceTypes: array<string, string>, keys: array<string, string>, images: array<string, string>, vpcs: array<string, string>}|int
     */
    protected function fetchAccountData(): array|int
    {
        try {
            return $this->io->promptSpin(
                fn () => [
                    'instanceTypes' => $this->aws->account->getAvailableInstanceTypes(),
                    'keys' => $this->aws->account->getPublicKeys(),
                    'images' => $this->aws->account->getAvailableImages(),
                    'vpcs' => $this->aws->account->getUserVpcs(),
                ],
                'Retrieving account information...'
            );
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Ensure deployer security group exists.
     */
    protected function ensureSecurityGroup(string $vpcId): string|int
    {
        try {
            $securityGroupId = $this->io->promptSpin(
                fn () => $this->aws->securityGroup->ensureDeployerSecurityGroup($vpcId),
                'Configuring security group...'
            );

            $this->yay('Security group ready');

            return $securityGroupId;
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Provision the EC2 instance.
     *
     * @param array{name: string, instanceType: string, ami: string, amiName: string, keyPair: string, privateKeyPath: string, vpcId: string, subnetId: string, publicIp: bool, monitoring: bool} $deets
     */
    protected function provisionInstance(array $deets, string $securityGroupId): string|int
    {
        try {
            $instanceData = $this->io->promptSpin(
                fn () => $this->aws->instance->createInstance(
                    name: $deets['name'],
                    instanceType: $deets['instanceType'],
                    imageId: $deets['ami'],
                    keyName: $deets['keyPair'],
                    subnetId: $deets['subnetId'],
                    securityGroupId: $securityGroupId,
                    publicIp: $deets['publicIp'],
                    monitoring: $deets['monitoring']
                ),
                'Provisioning instance...'
            );

            $this->yay("Instance provisioned (ID: {$instanceData['id']})");

            return $instanceData['id'];
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Configure instance and add to inventory with automatic rollback on failure.
     *
     * @param array{name: string, instanceType: string, ami: string, amiName: string, keyPair: string, privateKeyPath: string, vpcId: string, subnetId: string, publicIp: bool, monitoring: bool} $deets
     */
    protected function configureInstance(string $instanceId, array $deets): int
    {
        $provisionSuccess = false;

        try {
            $this->io->promptSpin(
                fn () => $this->aws->instance->waitForInstanceReady($instanceId),
                'Waiting for instance to be running...'
            );

            $this->yay('Instance is running');

            $ipAddress = $this->aws->instance->getInstanceIp($instanceId);
            $username = $this->aws->instance->getDefaultUsername($deets['amiName']);

            $server = $this->getServerInfo(new ServerDTO(
                name: $deets['name'],
                host: $ipAddress,
                port: 22,
                username: $username,
                privateKeyPath: $deets['privateKeyPath'],
                provider: 'aws',
                instanceId: $instanceId
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
            $this->rollbackInstance($instanceId);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Rollback instance on failure.
     */
    protected function rollbackInstance(string $instanceId): void
    {
        try {
            $this->io->promptSpin(
                fn () => $this->aws->instance->terminateInstance($instanceId),
                'Rolling back instance...'
            );

            $this->warn('Rolled back instance');
        } catch (\Throwable $cleanupError) {
            $this->nay($cleanupError->getMessage());
        }
    }

    /**
     * Gather provisioning details from user input or CLI options.
     *
     * @param array{instanceTypes: array<string, string>, keys: array<string, string>, images: array<string, string>, vpcs: array<string, string>} $accountData
     *
     * @return array{name: string, instanceType: string, ami: string, amiName: string, keyPair: string, privateKeyPath: string, vpcId: string, subnetId: string, publicIp: bool, monitoring: bool}|int
     */
    protected function gatherProvisioningDeets(array $accountData): array|int
    {
        try {
            $this->validateAccountDataAvailability($accountData);

            $coreDeets = $this->gatherCoreProvisioningDeets($accountData);

            if (is_int($coreDeets)) {
                return Command::FAILURE;
            }

            $networkDeets = $this->gatherNetworkDeets($accountData['vpcs']);

            if (is_int($networkDeets)) {
                return Command::FAILURE;
            }

            $optionalDeets = $this->gatherOptionalDeets();
        } catch (ValidationException|\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        return array_merge($coreDeets, $networkDeets, $optionalDeets);
    }

    /**
     * Validate that required account data is available.
     *
     * @param array{instanceTypes: array<string, string>, keys: array<string, string>, images: array<string, string>, vpcs: array<string, string>} $accountData
     *
     * @throws ValidationException
     */
    protected function validateAccountDataAvailability(array $accountData): void
    {
        if (0 === count($accountData['instanceTypes'])) {
            throw new ValidationException('No instance types available in this region');
        }

        if (0 === count($accountData['images'])) {
            throw new ValidationException('No supported OS images available in this region');
        }

        if (0 === count($accountData['vpcs'])) {
            throw new ValidationException('No VPCs found in this region');
        }
    }

    /**
     * Gather core provisioning details (name, instance type, AMI, key pair, private key path).
     *
     * @param array{instanceTypes: array<string, string>, keys: array<string, string>, images: array<string, string>, vpcs: array<string, string>} $accountData
     *
     * @return array{name: string, instanceType: string, ami: string, amiName: string, keyPair: string, privateKeyPath: string}|int
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

        /** @var string $instanceType */
        $instanceType = $this->io->getValidatedOptionOrPrompt(
            'instance-type',
            fn ($validate) => $this->io->promptSelect(
                label: 'Select instance type:',
                options: $accountData['instanceTypes'],
                hint: 'Choose CPU and RAM configuration',
                default: 't3.micro',
                scroll: 15,
                validate: $validate
            ),
            fn ($value) => $this->validateAwsInstanceType($value, $accountData['instanceTypes'])
        );

        /** @var string $ami */
        $ami = $this->io->getValidatedOptionOrPrompt(
            'ami',
            fn ($validate) => $this->io->promptSelect(
                label: 'Select OS image:',
                options: $accountData['images'],
                hint: 'Supported Linux distributions',
                default: '',
                scroll: 15,
                validate: $validate
            ),
            fn ($value) => $this->validateAwsImage($value, $accountData['images'])
        );

        $amiName = $accountData['images'][$ami] ?? '';

        /** @var string $keyPair */
        $keyPair = $this->io->getValidatedOptionOrPrompt(
            'key-pair',
            fn ($validate) => $this->io->promptSelect(
                label: 'Select EC2 key pair for instance access:',
                options: $accountData['keys'],
                validate: $validate
            ),
            fn (mixed $value): ?string => $this->validateAwsSSHKeyName($value, $accountData['keys'])
        );

        $privateKeyPath = $this->promptPrivateKeyPath();

        return [
            'name' => $name,
            'instanceType' => $instanceType,
            'ami' => $ami,
            'amiName' => $amiName,
            'keyPair' => $keyPair,
            'privateKeyPath' => $privateKeyPath,
        ];
    }

    /**
     * Gather network configuration (VPC and subnet).
     *
     * @param array<string, string> $vpcs
     *
     * @return array{vpcId: string, subnetId: string}|int
     */
    protected function gatherNetworkDeets(array $vpcs): array|int
    {
        /** @var string $vpcId */
        $vpcId = $this->io->getValidatedOptionOrPrompt(
            'vpc',
            fn ($validate) => $this->io->promptSelect(
                label: 'Select VPC:',
                options: $vpcs,
                hint: 'Virtual Private Cloud for network isolation',
                validate: $validate
            ),
            fn ($value) => $this->validateAwsVPC($value, $vpcs)
        );

        $subnets = $this->aws->account->getUserSubnets($vpcId);

        if (0 === count($subnets)) {
            throw new ValidationException("No subnets found in VPC {$vpcId}");
        }

        /** @var string $subnetId */
        $subnetId = $this->io->getValidatedOptionOrPrompt(
            'subnet',
            fn ($validate) => $this->io->promptSelect(
                label: 'Select subnet:',
                options: $subnets,
                hint: 'Choose availability zone and network',
                validate: $validate
            ),
            fn ($value) => $this->validateAwsSubnet($value, $subnets)
        );

        return [
            'vpcId' => $vpcId,
            'subnetId' => $subnetId,
        ];
    }

    /**
     * Gather optional parameters (public IP, monitoring).
     *
     * @return array{publicIp: bool, monitoring: bool}
     */
    protected function gatherOptionalDeets(): array
    {
        $publicIp = $this->io->getBooleanOptionOrPrompt(
            'public-ip',
            fn () => $this->io->promptConfirm(
                label: 'Assign public IP address?',
                default: true,
                hint: 'Required for internet access'
            )
        );

        $monitoring = $this->io->getBooleanOptionOrPrompt(
            'monitoring',
            fn () => $this->io->promptConfirm(
                label: 'Enable detailed monitoring?',
                default: false,
                hint: 'CloudWatch detailed metrics (extra cost)'
            )
        );

        return [
            'publicIp' => $publicIp,
            'monitoring' => $monitoring,
        ];
    }
}
