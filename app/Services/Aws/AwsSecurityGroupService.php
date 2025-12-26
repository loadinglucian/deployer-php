<?php

declare(strict_types=1);

namespace DeployerPHP\Services\Aws;

/**
 * AWS EC2 security group management service.
 *
 * Handles the shared "deployer" security group for all deployer-provisioned instances.
 */
class AwsSecurityGroupService extends BaseAwsService
{
    private const SECURITY_GROUP_NAME = 'deployer';

    private const SECURITY_GROUP_DESCRIPTION = 'Managed by Deployer - allows all traffic (use server:firewall for rules)';

    /**
     * Ensure the "deployer" security group exists in the VPC.
     *
     * Creates it if it doesn't exist. Returns the security group ID.
     *
     * @param string $vpcId VPC ID where the security group should exist
     *
     * @return string Security group ID
     *
     * @throws \RuntimeException If creation fails
     */
    public function ensureDeployerSecurityGroup(string $vpcId): string
    {
        // Check if it already exists
        $existingId = $this->findDeployerSecurityGroup($vpcId);

        if (null !== $existingId) {
            return $existingId;
        }

        // Create the security group
        return $this->createDeployerSecurityGroup($vpcId);
    }

    /**
     * Find the "deployer" security group in a VPC.
     *
     * @param string $vpcId VPC ID to search in
     *
     * @return string|null Security group ID if found, null otherwise
     */
    public function findDeployerSecurityGroup(string $vpcId): ?string
    {
        $ec2 = $this->createEc2Client();

        try {
            $result = $ec2->describeSecurityGroups([
                'Filters' => [
                    ['Name' => 'vpc-id', 'Values' => [$vpcId]],
                    ['Name' => 'group-name', 'Values' => [self::SECURITY_GROUP_NAME]],
                ],
            ]);

            /** @var list<array<string, mixed>> $securityGroups */
            $securityGroups = $result['SecurityGroups'] ?? [];

            if (!empty($securityGroups)) {
                /** @var array<string, mixed> $securityGroup */
                $securityGroup = $securityGroups[0];
                /** @var string $groupId */
                $groupId = $securityGroup['GroupId'];

                return $groupId;
            }

            return null;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to search for security group: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Create the "deployer" security group with allow-all rules.
     *
     * @param string $vpcId VPC ID where to create the security group
     *
     * @return string New security group ID
     *
     * @throws \RuntimeException If creation fails
     */
    private function createDeployerSecurityGroup(string $vpcId): string
    {
        $ec2 = $this->createEc2Client();
        $groupId = null;

        try {
            // Create the security group
            $createResult = $ec2->createSecurityGroup([
                'GroupName' => self::SECURITY_GROUP_NAME,
                'Description' => self::SECURITY_GROUP_DESCRIPTION,
                'VpcId' => $vpcId,
                'TagSpecifications' => [
                    [
                        'ResourceType' => 'security-group',
                        'Tags' => [
                            ['Key' => 'Name', 'Value' => self::SECURITY_GROUP_NAME],
                            ['Key' => 'ManagedBy', 'Value' => 'deployer'],
                        ],
                    ],
                ],
            ]);

            /** @var string $groupId */
            $groupId = $createResult['GroupId'];

            // Add inbound rule: allow all traffic from anywhere
            $ec2->authorizeSecurityGroupIngress([
                'GroupId' => $groupId,
                'IpPermissions' => [
                    [
                        'IpProtocol' => '-1', // All protocols
                        'IpRanges' => [
                            ['CidrIp' => '0.0.0.0/0', 'Description' => 'Allow all IPv4 inbound'],
                        ],
                        'Ipv6Ranges' => [
                            ['CidrIpv6' => '::/0', 'Description' => 'Allow all IPv6 inbound'],
                        ],
                    ],
                ],
            ]);

            // Outbound is already allow-all by default, but let's be explicit
            // (Default SG rules already allow all outbound, so this is just for clarity)

            return $groupId;
        } catch (\Throwable $e) {
            // Clean up orphaned security group if ingress rules failed
            if (null !== $groupId) {
                try {
                    $ec2->deleteSecurityGroup(['GroupId' => $groupId]);
                } catch (\Throwable) {
                    // Cleanup failed - include in error message
                    throw new \RuntimeException(
                        "Failed to configure security group ingress rules: {$e->getMessage()}. " .
                        "An orphaned security group (ID: {$groupId}) may exist and should be manually deleted.",
                        0,
                        $e
                    );
                }
            }

            throw new \RuntimeException('Failed to create security group: ' . $e->getMessage(), 0, $e);
        }
    }
}
