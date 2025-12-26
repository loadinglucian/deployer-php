<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Pro\Aws;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\DTOs\ServerDTO;
use DeployerPHP\Exceptions\ValidationException;
use DeployerPHP\Traits\AwsTrait;
use DeployerPHP\Traits\KeysTrait;
use DeployerPHP\Traits\PlaybooksTrait;
use DeployerPHP\Traits\ServersTrait;
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
            ->addOption('instance-type', null, InputOption::VALUE_REQUIRED, 'Full instance type (e.g., t3.large) - skips family/size prompts')
            ->addOption('instance-family', null, InputOption::VALUE_REQUIRED, 'Instance family (e.g., t3, m6i, c7g)')
            ->addOption('instance-size', null, InputOption::VALUE_REQUIRED, 'Instance size (e.g., micro, large, xlarge)')
            ->addOption('ami', null, InputOption::VALUE_REQUIRED, 'AMI ID')
            ->addOption('key-pair', null, InputOption::VALUE_REQUIRED, 'AWS key pair name')
            ->addOption('private-key-path', null, InputOption::VALUE_REQUIRED, 'SSH private key path')
            ->addOption('vpc', null, InputOption::VALUE_REQUIRED, 'VPC ID')
            ->addOption('subnet', null, InputOption::VALUE_REQUIRED, 'Subnet ID')
            ->addOption('disk-size', null, InputOption::VALUE_REQUIRED, 'Root disk size in GB (default: 8)')
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

        $this->info("Region: {$this->aws->getRegion()}");

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
            'disk-size' => $deets['diskSize'],
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
     * @return array{instanceFamilies: array<string, string>, validFamilyNames: array<int, string>, keys: array<string, string>, images: array<string, string>, vpcs: array<string, string>}|int
     */
    protected function fetchAccountData(): array|int
    {
        try {
            return $this->io->promptSpin(
                fn () => [
                    'instanceFamilies' => $this->aws->account->getInstanceFamilies(),
                    'validFamilyNames' => $this->aws->account->getValidFamilyNames(),
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
     * @param array{name: string, instanceType: string, ami: string, amiName: string, keyPair: string, privateKeyPath: string, vpcId: string, subnetId: string, diskSize: int, monitoring: bool} $deets
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
                    monitoring: $deets['monitoring'],
                    diskSize: $deets['diskSize']
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
     * @param array{name: string, instanceType: string, ami: string, amiName: string, keyPair: string, privateKeyPath: string, vpcId: string, subnetId: string, diskSize: int, monitoring: bool} $deets
     */
    protected function configureInstance(string $instanceId, array $deets): int
    {
        $provisionSuccess = false;
        $elasticIpAllocationId = null;

        try {
            $this->io->promptSpin(
                fn () => $this->aws->instance->waitForInstanceReady($instanceId),
                'Waiting for instance to be running...'
            );

            $this->yay('Instance is running');

            //
            // Allocate and associate Elastic IP

            $elasticIp = $this->io->promptSpin(
                fn () => $this->aws->instance->allocateElasticIp(),
                'Allocating Elastic IP...'
            );

            $elasticIpAllocationId = $elasticIp['allocationId'];

            $this->io->promptSpin(
                fn () => $this->aws->instance->associateElasticIp($elasticIpAllocationId, $instanceId),
                'Associating Elastic IP with instance...'
            );

            $this->yay('Elastic IP allocated (ID: ' . $elasticIpAllocationId . ')');

            //
            // Add to inventory

            $ipAddress = $elasticIp['publicIp'];
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
            $this->rollbackInstance($instanceId, $elasticIpAllocationId);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Rollback instance and associated resources on failure.
     */
    protected function rollbackInstance(string $instanceId, ?string $elasticIpAllocationId = null): void
    {
        // Release Elastic IP first (if allocated)
        if (null !== $elasticIpAllocationId) {
            try {
                $this->io->promptSpin(
                    fn () => $this->aws->instance->releaseElasticIp($elasticIpAllocationId),
                    'Releasing Elastic IP...'
                );

                $this->warn('Released Elastic IP');
            } catch (\Throwable $cleanupError) {
                $this->nay($cleanupError->getMessage());
            }
        }

        // Terminate instance
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
     * @param array{instanceFamilies: array<string, string>, validFamilyNames: array<int, string>, keys: array<string, string>, images: array<string, string>, vpcs: array<string, string>} $accountData
     *
     * @return array{name: string, instanceType: string, ami: string, amiName: string, keyPair: string, privateKeyPath: string, vpcId: string, subnetId: string, diskSize: int, monitoring: bool}|int
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
     * @param array{instanceFamilies: array<string, string>, validFamilyNames: array<int, string>, keys: array<string, string>, images: array<string, string>, vpcs: array<string, string>} $accountData
     *
     * @throws ValidationException
     */
    protected function validateAccountDataAvailability(array $accountData): void
    {
        if (0 === count($accountData['instanceFamilies'])) {
            throw new ValidationException('No instance families available');
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
     * @param array{instanceFamilies: array<string, string>, validFamilyNames: array<int, string>, keys: array<string, string>, images: array<string, string>, vpcs: array<string, string>} $accountData
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

        //
        // Instance type selection (two-step or direct)

        $instanceType = $this->gatherInstanceType($accountData);

        if (is_int($instanceType)) {
            return Command::FAILURE;
        }

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
     * Gather instance type via direct option or two-step family/size selection.
     *
     * Supports three modes:
     * 1. --instance-type (full type like t3.large) - backwards compatible
     * 2. --instance-family + --instance-size (CLI two-step)
     * 3. Interactive two-step prompts
     *
     * @param array{instanceFamilies: array<string, string>, validFamilyNames: array<int, string>, keys: array<string, string>, images: array<string, string>, vpcs: array<string, string>} $accountData
     *
     * @return string|int Instance type string on success, Command::FAILURE on error
     */
    protected function gatherInstanceType(array $accountData): string|int
    {
        /** @var string|null $directType */
        $directType = $this->io->getOptionValue('instance-type');

        //
        // Mode 1: Direct --instance-type option (backwards compatible)

        if (null !== $directType) {
            $error = $this->validateAwsFullInstanceType($directType, $accountData['validFamilyNames']);

            if (null !== $error) {
                $this->nay($error);

                return Command::FAILURE;
            }

            // Verify the specific type exists in AWS
            $parts = explode('.', $directType);
            $family = $parts[0];

            $availableTypes = $this->io->promptSpin(
                fn () => $this->aws->account->getAvailableInstanceTypes($family),
                "Verifying instance type '{$directType}'..."
            );

            if (!isset($availableTypes[$directType])) {
                $this->nay("Instance type '{$directType}' is not available in this region");

                return Command::FAILURE;
            }

            return $directType;
        }

        //
        // Mode 2 & 3: Two-step selection (CLI options or interactive)

        /** @var string $family */
        $family = $this->io->getValidatedOptionOrPrompt(
            'instance-family',
            fn ($validate) => $this->io->promptSelect(
                label: 'Select instance family:',
                options: $accountData['instanceFamilies'],
                hint: 'Choose instance category based on workload',
                default: 't3',
                scroll: 15,
                validate: $validate
            ),
            fn ($value) => $this->validateAwsInstanceFamily($value, $accountData['instanceFamilies'])
        );

        //
        // Fetch available sizes for the selected family

        $availableTypes = $this->io->promptSpin(
            fn () => $this->aws->account->getAvailableInstanceTypes($family),
            "Fetching available {$family} sizes..."
        );

        if (0 === count($availableTypes)) {
            $this->nay("No instance sizes available for family '{$family}' in this region");

            return Command::FAILURE;
        }

        //
        // Select specific size

        /** @var string $instanceType */
        $instanceType = $this->io->getValidatedOptionOrPrompt(
            'instance-size',
            fn ($validate) => $this->io->promptSelect(
                label: "Select {$family} size:",
                options: $availableTypes,
                hint: 'Choose CPU and RAM configuration',
                scroll: 15,
                validate: $validate
            ),
            fn ($value) => $this->validateAwsInstanceSizeInput($value, $family, $availableTypes)
        );

        // If --instance-size was provided as just the size (e.g., "large"), combine with family
        if (!str_contains($instanceType, '.')) {
            $instanceType = "{$family}.{$instanceType}";
        }

        return $instanceType;
    }

    /**
     * Validate instance size input.
     *
     * Handles both full type (t3.large) and size-only (large) formats.
     *
     * @param array<string, string> $availableTypes Available instance types for the family
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateAwsInstanceSizeInput(mixed $size, string $family, array $availableTypes): ?string
    {
        if (!is_string($size)) {
            return 'Instance size must be a string';
        }

        if ('' === trim($size)) {
            return 'Instance size cannot be empty';
        }

        // Check if it's a full instance type (e.g., t3.large)
        if (str_contains($size, '.')) {
            if (!isset($availableTypes[$size])) {
                return "Invalid instance type: '{$size}' is not available in this region";
            }

            return null;
        }

        // It's just the size (e.g., "large"), combine with family
        $fullType = "{$family}.{$size}";

        if (!isset($availableTypes[$fullType])) {
            $validSizes = array_map(
                fn ($type) => explode('.', $type)[1] ?? $type,
                array_keys($availableTypes)
            );
            $validSizesStr = implode(', ', $validSizes);

            return "Invalid size: '{$size}'. Valid sizes for {$family}: {$validSizesStr}";
        }

        return null;
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
     * Gather optional parameters (disk size, monitoring).
     *
     * @return array{diskSize: int, monitoring: bool}
     */
    protected function gatherOptionalDeets(): array
    {
        //
        // Disk size

        /** @var string $diskSizeInput */
        $diskSizeInput = $this->io->getValidatedOptionOrPrompt(
            'disk-size',
            fn ($validate) => $this->io->promptText(
                label: 'Root disk size (GB):',
                default: '8',
                required: true,
                validate: $validate
            ),
            fn ($value) => $this->validateAwsDiskSize($value)
        );

        $diskSize = (int) $diskSizeInput;

        //
        // Monitoring

        $monitoring = $this->io->getBooleanOptionOrPrompt(
            'monitoring',
            fn () => $this->io->promptConfirm(
                label: 'Enable detailed monitoring?',
                default: false,
                hint: 'CloudWatch detailed metrics (extra cost)'
            )
        );

        return [
            'diskSize' => $diskSize,
            'monitoring' => $monitoring,
        ];
    }
}
