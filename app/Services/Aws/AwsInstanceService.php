<?php

declare(strict_types=1);

namespace Deployer\Services\Aws;

/**
 * AWS EC2 instance management service.
 *
 * Handles creating, terminating, and monitoring EC2 instances.
 */
class AwsInstanceService extends BaseAwsService
{
    /**
     * Create a new EC2 instance with the specified configuration.
     *
     * @param string $name Instance name (used for Name tag)
     * @param string $instanceType Instance type (e.g., t3.micro)
     * @param string $imageId AMI ID
     * @param string $keyName EC2 key pair name
     * @param string $subnetId Subnet ID
     * @param string $securityGroupId Security group ID
     * @param bool $publicIp Associate public IP address
     * @param bool $monitoring Enable detailed monitoring
     *
     * @return array{id: string, name: string} Instance data
     *
     * @throws \RuntimeException If creation fails
     */
    public function createInstance(
        string $name,
        string $instanceType,
        string $imageId,
        string $keyName,
        string $subnetId,
        string $securityGroupId,
        bool $publicIp = true,
        bool $monitoring = false
    ): array {
        $ec2 = $this->createEc2Client();

        try {
            $result = $ec2->runInstances([
                'ImageId' => $imageId,
                'InstanceType' => $instanceType,
                'KeyName' => $keyName,
                'MinCount' => 1,
                'MaxCount' => 1,
                'Monitoring' => [
                    'Enabled' => $monitoring,
                ],
                'NetworkInterfaces' => [
                    [
                        'DeviceIndex' => 0,
                        'SubnetId' => $subnetId,
                        'Groups' => [$securityGroupId],
                        'AssociatePublicIpAddress' => $publicIp,
                    ],
                ],
                'TagSpecifications' => [
                    [
                        'ResourceType' => 'instance',
                        'Tags' => [
                            ['Key' => 'Name', 'Value' => $name],
                            ['Key' => 'ManagedBy', 'Value' => 'deployer'],
                        ],
                    ],
                ],
            ]);

            /** @var list<array<string, mixed>> $instances */
            $instances = $result['Instances'] ?? [];
            /** @var array<string, mixed> $instance */
            $instance = $instances[0];
            /** @var string $instanceId */
            $instanceId = $instance['InstanceId'];

            return [
                'id' => $instanceId,
                'name' => $name,
            ];
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to create instance: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get the current state of an EC2 instance.
     *
     * @throws \RuntimeException If status check fails
     */
    public function getInstanceStatus(string $instanceId): string
    {
        $ec2 = $this->createEc2Client();

        try {
            $result = $ec2->describeInstances([
                'InstanceIds' => [$instanceId],
            ]);

            /** @var list<array<string, mixed>> $reservations */
            $reservations = $result['Reservations'] ?? [];

            if (empty($reservations) || empty($reservations[0]['Instances'])) {
                throw new \RuntimeException("Instance {$instanceId} not found");
            }

            /** @var list<array<string, mixed>> $instances */
            $instances = $reservations[0]['Instances'];
            /** @var array<string, mixed> $instance */
            $instance = $instances[0];
            /** @var array<string, mixed> $state */
            $state = $instance['State'];
            /** @var string $stateName */
            $stateName = $state['Name'];

            return $stateName;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to get instance status: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Wait for an EC2 instance to reach the "running" state.
     *
     * @param string $instanceId Instance ID
     * @param int $timeoutSeconds Maximum time to wait (default: 300 = 5 minutes)
     * @param int $pollIntervalSeconds Time between status checks (default: 5)
     *
     * @throws \RuntimeException If timeout is reached or polling fails
     */
    public function waitForInstanceReady(
        string $instanceId,
        int $timeoutSeconds = 300,
        int $pollIntervalSeconds = 5
    ): void {
        $startTime = time();

        while (true) {
            $status = $this->getInstanceStatus($instanceId);

            if ('running' === $status) {
                return;
            }

            // Instance entered a terminal failed state
            if (in_array($status, ['terminated', 'shutting-down', 'stopping', 'stopped'], true)) {
                throw new \RuntimeException(
                    "Instance (ID: {$instanceId}) entered unexpected state: {$status}"
                );
            }

            if ((time() - $startTime) >= $timeoutSeconds) {
                throw new \RuntimeException(
                    "Timeout waiting for instance (ID: {$instanceId}) to become running (current status: {$status})"
                );
            }

            sleep($pollIntervalSeconds);
        }
    }

    /**
     * Get the public IPv4 address of an EC2 instance.
     *
     * @throws \RuntimeException If IP retrieval fails or no public IP found
     */
    public function getInstanceIp(string $instanceId): string
    {
        $ec2 = $this->createEc2Client();

        try {
            $result = $ec2->describeInstances([
                'InstanceIds' => [$instanceId],
            ]);

            /** @var list<array<string, mixed>> $reservations */
            $reservations = $result['Reservations'] ?? [];

            if (empty($reservations) || empty($reservations[0]['Instances'])) {
                throw new \RuntimeException("Instance {$instanceId} not found");
            }

            /** @var list<array<string, mixed>> $instances */
            $instances = $reservations[0]['Instances'];
            /** @var array<string, mixed> $instance */
            $instance = $instances[0];

            /** @var string|null $publicIp */
            $publicIp = $instance['PublicIpAddress'] ?? null;

            if (null === $publicIp || '' === $publicIp) {
                throw new \RuntimeException('No public IP address found for instance');
            }

            return $publicIp;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to get instance IP: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Terminate an EC2 instance.
     *
     * Silently succeeds if instance doesn't exist.
     *
     * @param string $instanceId Instance ID to terminate
     *
     * @throws \RuntimeException If termination fails (non-404 errors)
     */
    public function terminateInstance(string $instanceId): void
    {
        $ec2 = $this->createEc2Client();

        try {
            $ec2->terminateInstances([
                'InstanceIds' => [$instanceId],
            ]);
        } catch (\Throwable $e) {
            // Check if instance doesn't exist - silently succeed
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'not found') || str_contains($message, 'does not exist')) {
                return;
            }

            throw new \RuntimeException("Failed to terminate instance: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Determine the default SSH username based on AMI name.
     *
     * @param string $amiName The AMI name or description
     *
     * @return string The default SSH username
     */
    public function getDefaultUsername(string $amiName): string
    {
        $amiNameLower = strtolower($amiName);

        if (str_contains($amiNameLower, 'ubuntu')) {
            return 'ubuntu';
        }

        if (str_contains($amiNameLower, 'debian')) {
            return 'admin';
        }

        // Default to ubuntu for unknown AMIs
        return 'ubuntu';
    }
}
