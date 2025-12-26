<?php

declare(strict_types=1);

namespace DeployerPHP\Services\Aws;

use DeployerPHP\Enums\Distribution;

/**
 * AWS account data service.
 *
 * Handles fetching account-level resources: regions, instance types, images, VPCs, subnets, SSH keys.
 */
class AwsAccountService extends BaseAwsService
{
    /**
     * Instance type families organized by category.
     *
     * @var array<string, array{label: string, families: array<string, string>}>
     */
    private const INSTANCE_TYPE_FAMILIES = [
        'burstable' => [
            'label' => 'Burstable (variable workloads, cost-effective)',
            'families' => [
                't3' => 'Intel, baseline + burst',
                't3a' => 'AMD, ~10% cheaper than t3',
                't4g' => 'ARM/Graviton, best price/performance',
            ],
        ],
        'general' => [
            'label' => 'General Purpose (balanced CPU/memory)',
            'families' => [
                'm6i' => 'Intel, current gen',
                'm6a' => 'AMD, cost-optimized',
                'm7g' => 'ARM/Graviton, latest gen',
            ],
        ],
        'compute' => [
            'label' => 'Compute Optimized (CPU-intensive)',
            'families' => [
                'c6i' => 'Intel, current gen',
                'c6a' => 'AMD, cost-optimized',
                'c7g' => 'ARM/Graviton, best compute value',
            ],
        ],
        'memory' => [
            'label' => 'Memory Optimized (large databases)',
            'families' => [
                'r6i' => 'Intel, current gen',
                'r6a' => 'AMD, cost-optimized',
                'r7g' => 'ARM/Graviton, latest gen',
            ],
        ],
    ];


    //
    // Account data retrieval
    // ----

    /**
     * Get instance type families for selection.
     *
     * Returns families grouped by category with descriptions.
     *
     * @return array<string, string> Array of family => description
     */
    public function getInstanceFamilies(): array
    {
        $options = [];

        foreach (self::INSTANCE_TYPE_FAMILIES as $category) {
            $label = $category['label'];
            $families = $category['families'];

            foreach ($families as $family => $description) {
                $options[$family] = "{$family} - {$description} [{$label}]";
            }
        }

        return $options;
    }

    /**
     * Get valid instance family names.
     *
     * @return array<int, string>
     */
    public function getValidFamilyNames(): array
    {
        $families = [];

        foreach (self::INSTANCE_TYPE_FAMILIES as $category) {
            $families = array_merge($families, array_keys($category['families']));
        }

        return $families;
    }

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
     * Get available EC2 instance types for a specific family.
     *
     * Paginates through all instance types and filters by family prefix.
     * AWS doesn't support wildcards in describeInstanceTypes, so we filter client-side.
     *
     * @return array<string, string> Array of instance type => description
     */
    public function getAvailableInstanceTypes(string $family): array
    {
        $ec2 = $this->createEc2Client();
        $familyPrefix = $family . '.';

        try {
            $instanceData = [];
            $nextToken = null;

            // Paginate through all instance types
            do {
                $params = ['MaxResults' => 100];

                if (null !== $nextToken) {
                    $params['NextToken'] = $nextToken;
                }

                $result = $ec2->describeInstanceTypes($params);

                /** @var list<array<string, mixed>> $types */
                $types = $result['InstanceTypes'] ?? [];

                foreach ($types as $type) {
                    /** @var string $instanceType */
                    $instanceType = $type['InstanceType'];

                    // Filter by family prefix
                    if (!str_starts_with($instanceType, $familyPrefix)) {
                        continue;
                    }

                    /** @var array<string, mixed> $vcpuInfo */
                    $vcpuInfo = $type['VCpuInfo'];
                    /** @var int $vcpus */
                    $vcpus = $vcpuInfo['DefaultVCpus'];
                    /** @var array<string, mixed> $memoryInfo */
                    $memoryInfo = $type['MemoryInfo'];
                    /** @var int|string $rawMemory */
                    $rawMemory = $memoryInfo['SizeInMiB'];
                    $memorySizeInMib = (int) $rawMemory;
                    $memoryLabel = $this->formatMemorySize($memorySizeInMib);

                    $instanceData[] = [
                        'type' => $instanceType,
                        'vcpus' => $vcpus,
                        'memory' => $memorySizeInMib,
                        'label' => "{$instanceType} - {$vcpus} vCPU, {$memoryLabel} RAM",
                    ];
                }

                /** @var string|null $nextToken */
                $nextToken = $result['NextToken'] ?? null;
            } while (null !== $nextToken);

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
            throw new \RuntimeException("Failed to fetch instance types for family '{$family}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate a full instance type exists.
     *
     * Used for backwards-compatible --instance-type option validation.
     */
    public function validateInstanceType(string $instanceType): bool
    {
        // Extract family from instance type (e.g., "t3.large" -> "t3")
        $parts = explode('.', $instanceType);

        if (2 !== count($parts)) {
            return false;
        }

        $family = $parts[0];
        $validFamilies = $this->getValidFamilyNames();

        if (!in_array($family, $validFamilies, true)) {
            return false;
        }

        // Verify the specific type exists by querying AWS
        $availableTypes = $this->getAvailableInstanceTypes($family);

        return isset($availableTypes[$instanceType]);
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
     * Format memory size for display.
     *
     * Shows MB for sub-GB amounts, GB otherwise.
     */
    private function formatMemorySize(int $sizeInMib): string
    {
        if (1024 > $sizeInMib) {
            return "{$sizeInMib}MB";
        }

        $sizeInGib = (int) ($sizeInMib / 1024);

        return "{$sizeInGib}GB";
    }

    /**
     * Filter images to keep only the latest 2 versions per distribution.
     *
     * @param array<int, array<string, mixed>> $images
     *
     * @return array<int, array<string, mixed>>
     */
    private function filterLatestImages(array $images): array
    {
        // Group by version key and keep latest image per version
        $grouped = [];
        foreach ($images as $image) {
            /** @var string $name */
            $name = $image['Name'] ?? '';
            $parsed = $this->parseImageVersion($name);

            if (null === $parsed) {
                continue;
            }

            [$distro, $version] = $parsed;
            $key = $distro . '-' . $version;

            /** @var string $creationDate */
            $creationDate = $image['CreationDate'] ?? '';

            if (!isset($grouped[$key]) || $creationDate > $grouped[$key]['CreationDate']) {
                $grouped[$key] = $image + ['_distro' => $distro, '_version' => $version];
            }
        }

        // Separate by distro
        $ubuntu = [];
        $debian = [];

        foreach ($grouped as $image) {
            /** @var string $version */
            $version = $image['_version'];

            if ('ubuntu' === $image['_distro']) {
                $ubuntu[$version] = $image;
            } else {
                $debian[$version] = $image;
            }
        }

        // Sort versions descending and limit to latest 2 each
        // Cast to string because PHP converts numeric keys like "12" to int
        uksort($ubuntu, fn ($a, $b) => version_compare((string) $b, (string) $a));
        uksort($debian, fn ($a, $b) => version_compare((string) $b, (string) $a));

        $ubuntu = array_slice($ubuntu, 0, 2, true);
        $debian = array_slice($debian, 0, 2, true);

        return array_values(array_merge($ubuntu, $debian));
    }

    /**
     * Parse image name to extract distribution and version.
     *
     * @return array{0: string, 1: string}|null Returns [distro, version] or null
     */
    private function parseImageVersion(string $name): ?array
    {
        // Ubuntu LTS only (xx.04 versions)
        if (preg_match('/ubuntu[^0-9]*(\d+\.04)/', $name, $matches)) {
            return ['ubuntu', $matches[1]];
        }

        // Debian major versions
        if (preg_match('/debian-(\d+)/', $name, $matches)) {
            return ['debian', $matches[1]];
        }

        return null;
    }

    /**
     * Format image description for display.
     */
    private function formatImageDescription(string $name): string
    {
        $parsed = $this->parseImageVersion($name);

        if (null === $parsed) {
            return '';
        }

        [$distro, $version] = $parsed;
        $distribution = Distribution::from($distro);

        return $distribution->formatVersion($version);
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
