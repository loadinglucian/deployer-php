<?php

declare(strict_types=1);

namespace Deployer\Console\Server;

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
    name: 'server:provision:aws',
    description: 'Provision a new AWS EC2 instance and add it to inventory'
)]
class ServerProvisionAwsCommand extends BaseCommand
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

        //
        // Initialize AWS API
        // ----

        if (Command::FAILURE === $this->initializeAwsAPI()) {
            return Command::FAILURE;
        }

        //
        // Retrieve AWS account data
        // ----

        try {
            $accountData = $this->io->promptSpin(
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

        $keys = $this->ensureAwsKeysAvailable($accountData['keys']);

        if (is_int($keys)) {
            return Command::FAILURE;
        }

        //
        // Gather provisioning details
        // ----

        $deets = $this->gatherProvisioningDeets($accountData);

        if (is_int($deets)) {
            return Command::FAILURE;
        }

        [
            'name' => $name,
            'instanceType' => $instanceType,
            'ami' => $ami,
            'amiName' => $amiName,
            'keyPair' => $keyPair,
            'privateKeyPath' => $privateKeyPath,
            'vpcId' => $vpcId,
            'subnetId' => $subnetId,
            'publicIp' => $publicIp,
            'monitoring' => $monitoring,
        ] = $deets;

        //
        // Ensure deployer security group exists
        // ----

        try {
            $securityGroupId = $this->io->promptSpin(
                fn () => $this->aws->securityGroup->ensureDeployerSecurityGroup($vpcId),
                'Configuring security group...'
            );

            $this->yay('Security group ready');
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        //
        // Provision instance
        // ----

        try {
            $instanceData = $this->io->promptSpin(
                fn () => $this->aws->instance->createInstance(
                    name: $name,
                    instanceType: $instanceType,
                    imageId: $ami,
                    keyName: $keyPair,
                    subnetId: $subnetId,
                    securityGroupId: $securityGroupId,
                    publicIp: $publicIp,
                    monitoring: $monitoring
                ),
                'Provisioning instance...'
            );

            $instanceId = $instanceData['id'];
            $this->yay("Instance provisioned (ID: {$instanceId})");
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        //
        // Configure instance with automatic rollback on failure
        // ----

        $provisionSuccess = false;

        try {
            // Wait for instance to become running
            $this->io->promptSpin(
                fn () => $this->aws->instance->waitForInstanceReady($instanceId),
                'Waiting for instance to be running...'
            );

            $this->yay('Instance is running');

            // Get instance IP address
            $ipAddress = $this->aws->instance->getInstanceIp($instanceId);

            // Determine username based on AMI
            $username = $this->aws->instance->getDefaultUsername($amiName);

            // Create server DTO
            $server = $this->getServerInfo(new ServerDTO(
                name: $name,
                host: $ipAddress,
                port: 22,
                username: $username,
                privateKeyPath: $privateKeyPath,
                provider: 'aws',
                instanceId: $instanceId
            ));

            if (!is_int($server)) {
                // Add to inventory
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
            try {
                $this->io->promptSpin(
                    fn () => $this->aws->instance->terminateInstance($instanceId),
                    'Rolling back instance...'
                );

                $this->warn('Rolled back instance');
            } catch (\Throwable $cleanupError) {
                $this->nay($cleanupError->getMessage());
            }

            return Command::FAILURE;
        }

        //
        // Show command replay
        // ----

        $this->commandReplay('server:provision:aws', [
            'name' => $name,
            'instance-type' => $instanceType,
            'ami' => $ami,
            'key-pair' => $keyPair,
            'private-key-path' => $privateKeyPath,
            'vpc' => $vpcId,
            'subnet' => $subnetId,
            'public-ip' => $publicIp,
            'monitoring' => $monitoring,
        ]);

        return Command::SUCCESS;
    }

    // ----
    // Helpers
    // ----

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
            //
            // Validate account data availability

            if (0 === count($accountData['instanceTypes'])) {
                throw new ValidationException('No instance types available in this region');
            }

            if (0 === count($accountData['images'])) {
                throw new ValidationException('No supported OS images available in this region');
            }

            if (0 === count($accountData['vpcs'])) {
                throw new ValidationException('No VPCs found in this region');
            }

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

            //
            // Select key pair

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

            //
            // Prompt for local private key path

            $privateKeyPath = $this->promptPrivateKeyPath();

            //
            // Select VPC

            /** @var string $vpcId */
            $vpcId = $this->io->getValidatedOptionOrPrompt(
                'vpc',
                fn ($validate) => $this->io->promptSelect(
                    label: 'Select VPC:',
                    options: $accountData['vpcs'],
                    hint: 'Virtual Private Cloud for network isolation',
                    validate: $validate
                ),
                fn ($value) => $this->validateAwsVPC($value, $accountData['vpcs'])
            );

            //
            // Fetch and select subnet

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

            //
            // Gather optional parameters

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
        } catch (ValidationException|\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        return [
            'name' => $name,
            'instanceType' => $instanceType,
            'ami' => $ami,
            'amiName' => $amiName,
            'keyPair' => $keyPair,
            'privateKeyPath' => $privateKeyPath,
            'vpcId' => $vpcId,
            'subnetId' => $subnetId,
            'publicIp' => $publicIp,
            'monitoring' => $monitoring,
        ];
    }
}
