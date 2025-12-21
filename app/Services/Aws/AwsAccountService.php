<?php

declare(strict_types=1);

namespace Deployer\Services\Aws;

/**
 * AWS account data service.
 *
 * Handles fetching account-level resources: regions, instance types, images, VPCs, subnets, SSH keys.
 */
class AwsAccountService extends BaseAwsService
{
    /**
     * Common instance type families for general-purpose workloads.
     *
     * @var array<int, string>
     */
    private const COMMON_INSTANCE_TYPES = [
        't2.micro',
        't2.small',
        't2.medium',
        't2.large',
        't3.micro',
        't3.small',
        't3.medium',
        't3.large',
        't3.xlarge',
        't3a.micro',
        't3a.small',
        't3a.medium',
        't3a.large',
        'm5.large',
        'm5.xlarge',
        'm6i.large',
        'm6i.xlarge',
        'c5.large',
        'c5.xlarge',
        'r5.large',
        'r5.xlarge',
    ];

    //
    // Account data retrieval
    // ----

    /**
     * Get available AWS regions.
     *
     * @return array<string, string> Array of region code => description
     */
    public function getAvailableRegions(): array
    {
        $ec2 = $this->createEc2Client();

        try {
            $result = $ec2->describeRegions([
                'AllRegions' => false, // Only enabled regions
            ]);

            $options = [];
            /** @var list<array<string, mixed>> $regions */
            $regions = $result['Regions'] ?? [];
            /** @var array<string, mixed> $region */
            foreach ($regions as $region) {
                /** @var string $regionName */
                $regionName = $region['RegionName'];
                $options[$regionName] = $this->getRegionDisplayName($regionName);
            }

            asort($options);

            return $options;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to fetch AWS regions: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get available EC2 instance types.
     *
     * Returns a curated list of common instance types with their specifications.
     *
     * @return array<string, string> Array of instance type => description
     */
    public function getAvailableInstanceTypes(): array
    {
        $ec2 = $this->createEc2Client();

        try {
            $result = $ec2->describeInstanceTypes([
                'InstanceTypes' => self::COMMON_INSTANCE_TYPES,
            ]);

            $instanceData = [];
            /** @var list<array<string, mixed>> $types */
            $types = $result['InstanceTypes'] ?? [];
            /** @var array<string, mixed> $type */
            foreach ($types as $type) {
                /** @var string $instanceType */
                $instanceType = $type['InstanceType'];
                /** @var array<string, mixed> $vcpuInfo */
                $vcpuInfo = $type['VCpuInfo'];
                /** @var int $vcpus */
                $vcpus = $vcpuInfo['DefaultVCpus'];
                /** @var array<string, mixed> $memoryInfo */
                $memoryInfo = $type['MemoryInfo'];
                /** @var int $memorySizeInMib */
                $memorySizeInMib = $memoryInfo['SizeInMiB'];
                $memory = (int) ($memorySizeInMib / 1024); // Convert MiB to GiB

                $instanceData[] = [
                    'type' => $instanceType,
                    'vcpus' => $vcpus,
                    'memory' => $memory,
                    'label' => "{$instanceType} - {$vcpus} vCPU, {$memory}GB RAM",
                ];
            }

            // Sort by vCPUs, then by memory
            usort($instanceData, function (array $a, array $b) {
                if ($a['vcpus'] !== $b['vcpus']) {
                    return $a['vcpus'] <=> $b['vcpus'];
                }

                return $a['memory'] <=> $b['memory'];
            });

            $options = [];
            foreach ($instanceData as $data) {
                $options[$data['type']] = $data['label'];
            }

            return $options;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to fetch instance types: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get available AMIs (filtered to Ubuntu and Debian only).
     *
     * @return array<string, string> Array of AMI ID => description
     */
    public function getAvailableImages(): array
    {
        $ec2 = $this->createEc2Client();

        try {
            // Fetch Ubuntu LTS images (Canonical)
            $ubuntuResult = $ec2->describeImages([
                'Owners' => ['099720109477'], // Canonical (Ubuntu)
                'Filters' => [
                    ['Name' => 'name', 'Values' => ['ubuntu/images/hvm-ssd*/ubuntu-*-*-amd64-server-*']],
                    ['Name' => 'state', 'Values' => ['available']],
                    ['Name' => 'architecture', 'Values' => ['x86_64']],
                    ['Name' => 'virtualization-type', 'Values' => ['hvm']],
                    ['Name' => 'root-device-type', 'Values' => ['ebs']],
                ],
            ]);

            // Fetch Debian images (Debian official)
            $debianResult = $ec2->describeImages([
                'Owners' => ['136693071363'], // Debian
                'Filters' => [
                    ['Name' => 'name', 'Values' => ['debian-*-amd64-*']],
                    ['Name' => 'state', 'Values' => ['available']],
                    ['Name' => 'architecture', 'Values' => ['x86_64']],
                    ['Name' => 'virtualization-type', 'Values' => ['hvm']],
                    ['Name' => 'root-device-type', 'Values' => ['ebs']],
                ],
            ]);

            /** @var array<int, array<string, mixed>> $ubuntuImages */
            $ubuntuImages = $ubuntuResult['Images'] ?? [];
            /** @var array<int, array<string, mixed>> $debianImages */
            $debianImages = $debianResult['Images'] ?? [];

            $images = array_merge($ubuntuImages, $debianImages);

            // Group by distribution and version, keep only the latest
            $latestImages = $this->filterLatestImages($images);

            $options = [];
            foreach ($latestImages as $image) {
                /** @var string $amiId */
                $amiId = $image['ImageId'];
                /** @var string $name */
                $name = $image['Name'];
                $description = $this->formatImageDescription($name);

                if ('' !== $description) {
                    $options[$amiId] = $description;
                }
            }

            asort($options);

            return $options;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to fetch AMIs: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get user's VPCs in the current region.
     *
     * @return array<string, string> Array of VPC ID => name
     */
    public function getUserVpcs(): array
    {
        $ec2 = $this->createEc2Client();

        try {
            $result = $ec2->describeVpcs();

            $options = [];
            /** @var list<array<string, mixed>> $vpcs */
            $vpcs = $result['Vpcs'] ?? [];
            /** @var array<string, mixed> $vpc */
            foreach ($vpcs as $vpc) {
                /** @var string $vpcId */
                $vpcId = $vpc['VpcId'];
                /** @var list<array{Key: string, Value: string}> $tags */
                $tags = $vpc['Tags'] ?? [];
                $name = $this->getTagValue($tags, 'Name') ?? 'Unnamed';
                /** @var bool $isDefaultVpc */
                $isDefaultVpc = $vpc['IsDefault'] ?? false;
                $isDefault = $isDefaultVpc ? ' (default)' : '';
                /** @var string $cidr */
                $cidr = $vpc['CidrBlock'];

                $options[$vpcId] = "{$name}{$isDefault} - {$cidr}";
            }

            return $options;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to fetch VPCs: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get subnets for a specific VPC.
     *
     * @return array<string, string> Array of subnet ID => description
     */
    public function getUserSubnets(string $vpcId): array
    {
        $ec2 = $this->createEc2Client();

        try {
            $result = $ec2->describeSubnets([
                'Filters' => [
                    ['Name' => 'vpc-id', 'Values' => [$vpcId]],
                ],
            ]);

            $options = [];
            /** @var list<array<string, mixed>> $subnets */
            $subnets = $result['Subnets'] ?? [];
            /** @var array<string, mixed> $subnet */
            foreach ($subnets as $subnet) {
                /** @var string $subnetId */
                $subnetId = $subnet['SubnetId'];
                /** @var list<array{Key: string, Value: string}> $tags */
                $tags = $subnet['Tags'] ?? [];
                $name = $this->getTagValue($tags, 'Name') ?? 'Unnamed';
                /** @var string $az */
                $az = $subnet['AvailabilityZone'];
                /** @var string $cidr */
                $cidr = $subnet['CidrBlock'];
                /** @var bool $isPublic */
                $isPublic = $subnet['MapPublicIpOnLaunch'] ?? false;
                $public = $isPublic ? 'public' : 'private';

                $options[$subnetId] = "{$name} ({$az}, {$public}) - {$cidr}";
            }

            return $options;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to fetch subnets: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get available EC2 key pairs.
     *
     * @return array<string, string> Array of key name => description
     */
    public function getPublicKeys(): array
    {
        $ec2 = $this->createEc2Client();

        try {
            $result = $ec2->describeKeyPairs();

            $options = [];
            /** @var list<array<string, mixed>> $keyPairs */
            $keyPairs = $result['KeyPairs'] ?? [];
            /** @var array<string, mixed> $key */
            foreach ($keyPairs as $key) {
                /** @var string $keyName */
                $keyName = $key['KeyName'];
                /** @var string $fingerprint */
                $fingerprint = $key['KeyFingerprint'] ?? '';
                $shortFingerprint = '' !== $fingerprint ? substr($fingerprint, 0, 20) . '...' : '';

                $options[$keyName] = "{$keyName} ({$shortFingerprint})";
            }

            return $options;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to fetch key pairs: ' . $e->getMessage(), 0, $e);
        }
    }

    //
    // Helpers
    // ----

    /**
     * Get human-readable display name for a region.
     */
    private function getRegionDisplayName(string $regionCode): string
    {
        $regionNames = [
            'us-east-1' => 'US East (N. Virginia)',
            'us-east-2' => 'US East (Ohio)',
            'us-west-1' => 'US West (N. California)',
            'us-west-2' => 'US West (Oregon)',
            'af-south-1' => 'Africa (Cape Town)',
            'ap-east-1' => 'Asia Pacific (Hong Kong)',
            'ap-south-1' => 'Asia Pacific (Mumbai)',
            'ap-south-2' => 'Asia Pacific (Hyderabad)',
            'ap-southeast-1' => 'Asia Pacific (Singapore)',
            'ap-southeast-2' => 'Asia Pacific (Sydney)',
            'ap-southeast-3' => 'Asia Pacific (Jakarta)',
            'ap-southeast-4' => 'Asia Pacific (Melbourne)',
            'ap-northeast-1' => 'Asia Pacific (Tokyo)',
            'ap-northeast-2' => 'Asia Pacific (Seoul)',
            'ap-northeast-3' => 'Asia Pacific (Osaka)',
            'ca-central-1' => 'Canada (Central)',
            'ca-west-1' => 'Canada West (Calgary)',
            'eu-central-1' => 'Europe (Frankfurt)',
            'eu-central-2' => 'Europe (Zurich)',
            'eu-west-1' => 'Europe (Ireland)',
            'eu-west-2' => 'Europe (London)',
            'eu-west-3' => 'Europe (Paris)',
            'eu-south-1' => 'Europe (Milan)',
            'eu-south-2' => 'Europe (Spain)',
            'eu-north-1' => 'Europe (Stockholm)',
            'il-central-1' => 'Israel (Tel Aviv)',
            'me-south-1' => 'Middle East (Bahrain)',
            'me-central-1' => 'Middle East (UAE)',
            'sa-east-1' => 'South America (São Paulo)',
        ];

        $displayName = $regionNames[$regionCode] ?? $regionCode;

        return "{$displayName} ({$regionCode})";
    }

    /**
     * Filter images to keep only the latest version of each distribution.
     *
     * @param array<int, array<string, mixed>> $images
     *
     * @return array<int, array<string, mixed>>
     */
    private function filterLatestImages(array $images): array
    {
        // Group by distribution key (ubuntu-22.04, debian-12, etc.)
        $grouped = [];
        foreach ($images as $image) {
            /** @var string $name */
            $name = $image['Name'] ?? '';
            $key = $this->getDistributionKey($name);

            if (null === $key) {
                continue;
            }

            /** @var string $creationDate */
            $creationDate = $image['CreationDate'] ?? '';

            if (!isset($grouped[$key]) || $creationDate > $grouped[$key]['CreationDate']) {
                $grouped[$key] = $image;
            }
        }

        return array_values($grouped);
    }

    /**
     * Extract distribution key from image name (e.g., "ubuntu-22.04", "debian-12").
     */
    private function getDistributionKey(string $name): ?string
    {
        // Ubuntu: ubuntu/images/hvm-ssd/ubuntu-jammy-22.04-amd64-server-20231117
        if (preg_match('/ubuntu[^0-9]*(\d+\.\d+)/', $name, $matches)) {
            return 'ubuntu-' . $matches[1];
        }

        // Debian: debian-12-amd64-20231013-1532
        if (preg_match('/debian-(\d+)/', $name, $matches)) {
            return 'debian-' . $matches[1];
        }

        return null;
    }

    /**
     * Format image description for display.
     */
    private function formatImageDescription(string $name): string
    {
        // Ubuntu
        if (preg_match('/ubuntu[^0-9]*(\d+\.\d+)/', $name, $matches)) {
            $version = $matches[1];
            $codename = $this->getUbuntuCodename($version);

            return "Ubuntu {$version} LTS ({$codename})";
        }

        // Debian
        if (preg_match('/debian-(\d+)/', $name, $matches)) {
            $version = $matches[1];
            $codename = $this->getDebianCodename($version);

            return "Debian {$version} ({$codename})";
        }

        return '';
    }

    /**
     * Get Ubuntu codename for a version number.
     */
    private function getUbuntuCodename(string $version): string
    {
        $codenames = [
            '20.04' => 'Focal Fossa',
            '22.04' => 'Jammy Jellyfish',
            '24.04' => 'Noble Numbat',
        ];

        return $codenames[$version] ?? 'LTS';
    }

    /**
     * Get Debian codename for a version number.
     */
    private function getDebianCodename(string $version): string
    {
        $codenames = [
            '11' => 'Bullseye',
            '12' => 'Bookworm',
            '13' => 'Trixie',
        ];

        return $codenames[$version] ?? 'Stable';
    }

    /**
     * Get value of a specific tag from tags array.
     *
     * @param array<int, array{Key: string, Value: string}> $tags
     */
    private function getTagValue(array $tags, string $key): ?string
    {
        foreach ($tags as $tag) {
            if ($tag['Key'] === $key) {
                return $tag['Value'];
            }
        }

        return null;
    }
}
