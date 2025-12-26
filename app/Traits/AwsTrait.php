<?php

declare(strict_types=1);

namespace DeployerPHP\Traits;

use DeployerPHP\Exceptions\ValidationException;
use DeployerPHP\Services\AwsService;
use DeployerPHP\Services\EnvService;
use DeployerPHP\Services\IoService;
use Symfony\Component\Console\Command\Command;

/**
 * Reusable AWS things.
 *
 * @property AwsService $aws
 * @property EnvService $env
 * @property IoService $io
 */
trait AwsTrait
{
    // ----
    // Helpers
    // ----

    //
    // API
    // ----

    /**
     * Initialize AWS API with credentials from environment.
     *
     * Retrieves AWS credentials (AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY)
     * and region (AWS_DEFAULT_REGION or AWS_REGION) from environment variables,
     * configures the AWS service, and verifies authentication with STS.
     * Displays error messages and exits on failure.
     *
     * @return int Command::SUCCESS on success, Command::FAILURE on error
     */
    protected function initializeAwsAPI(): int
    {
        try {
            /** @var string $accessKeyId */
            $accessKeyId = $this->env->get(['AWS_ACCESS_KEY_ID']);

            /** @var string $secretAccessKey */
            $secretAccessKey = $this->env->get(['AWS_SECRET_ACCESS_KEY']);

            /** @var string $region */
            $region = $this->env->get(['AWS_DEFAULT_REGION', 'AWS_REGION']);

            // Initialize AWS API
            $this->io->promptSpin(
                fn () => $this->aws->initialize($accessKeyId, $secretAccessKey, $region),
                'Initializing AWS API...'
            );

            return Command::SUCCESS;
        } catch (\InvalidArgumentException) {
            // Credential configuration issue
            $this->nay('AWS credentials not found in environment.');
            $this->nay('Set AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, and AWS_DEFAULT_REGION in your .env file.');

            return Command::FAILURE;
        } catch (\RuntimeException $e) {
            // API authentication failure
            $this->nay($e->getMessage());
            $this->nay('Check that your AWS credentials are valid and have not expired.');

            return Command::FAILURE;
        }
    }

    /**
     * Display a warning if no keys are available. Otherwise, return all keys.
     *
     * @param array<string, string>|null $keys Optional pre-fetched keys; if null, fetches from AWS API
     *
     * @return array<string, string>|int Returns array of keys (name => description) or Command::FAILURE
     */
    protected function ensureAwsKeysAvailable(?array $keys = null): array|int
    {
        //
        // Get all keys

        if (null === $keys) {
            try {
                $keys = $this->aws->account->getPublicKeys();
            } catch (\RuntimeException $e) {
                $this->nay('Failed to retrieve EC2 key pairs: ' . $e->getMessage());

                return Command::FAILURE;
            }
        }

        //
        // Check if no keys are available

        if (0 === count($keys)) {
            $this->info('No EC2 key pairs found in your AWS account for this region');
            $this->ul([
                'Run <fg=cyan>pro:aws:key:add</> to add a public SSH key',
            ]);

            return Command::FAILURE;
        }

        return $keys;
    }

    /**
     * Select a key from available keys via option or interactive prompt.
     *
     * @return array{name: string, description: string}|int Array with selected key name and description on success, or Command::FAILURE on error
     */
    protected function selectAwsKey(): array|int
    {
        //
        // Get all keys

        $availableKeys = $this->ensureAwsKeysAvailable();

        if (is_int($availableKeys)) {
            return Command::FAILURE;
        }

        //
        // Prompt for selection

        try {
            /** @var string $selectedKey */
            $selectedKey = $this->io->getValidatedOptionOrPrompt(
                'key',
                fn ($validate) => $this->io->promptSelect(
                    label: 'Select EC2 key pair:',
                    options: $availableKeys,
                    validate: $validate
                ),
                fn ($value) => $this->validateAwsKeySelection($value, $availableKeys)
            );
        } catch (ValidationException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        /** @var string $description */
        $description = $availableKeys[$selectedKey];

        return [
            'name' => $selectedKey,
            'description' => $description,
        ];
    }

    // ----
    // Validation
    // ----

    /**
     * Validate instance family against available families.
     *
     * @param array<string, string> $validFamilies Available instance families (family => description)
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateAwsInstanceFamily(mixed $family, array $validFamilies): ?string
    {
        if (!is_string($family)) {
            return 'Instance family must be a string';
        }

        if ('' === trim($family)) {
            return 'Instance family cannot be empty';
        }

        if (!isset($validFamilies[$family])) {
            $validFamilyNames = implode(', ', array_keys($validFamilies));

            return "Invalid instance family: '{$family}'. Valid families: {$validFamilyNames}";
        }

        return null;
    }

    /**
     * Validate instance type against available types.
     *
     * @param array<string, string> $validTypes Available instance types
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateAwsInstanceType(mixed $type, array $validTypes): ?string
    {
        if (!is_string($type)) {
            return 'Instance type must be a string';
        }

        if ('' === trim($type)) {
            return 'Instance type cannot be empty';
        }

        if (!isset($validTypes[$type])) {
            return "Invalid instance type: '{$type}' is not available in this region";
        }

        return null;
    }

    /**
     * Validate full instance type format and family.
     *
     * Used for backwards-compatible --instance-type option.
     *
     * @param array<int, string> $validFamilies List of valid family names
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateAwsFullInstanceType(mixed $type, array $validFamilies): ?string
    {
        if (!is_string($type)) {
            return 'Instance type must be a string';
        }

        if ('' === trim($type)) {
            return 'Instance type cannot be empty';
        }

        // Validate format: family.size (e.g., t3.large)
        if (!preg_match('/^[a-z0-9]+\.[a-z0-9]+$/i', $type)) {
            return "Invalid instance type format: '{$type}'. Expected format: family.size (e.g., t3.large)";
        }

        $parts = explode('.', $type);
        $family = $parts[0];

        if (!in_array($family, $validFamilies, true)) {
            $validFamilyNames = implode(', ', $validFamilies);

            return "Invalid instance family: '{$family}'. Valid families: {$validFamilyNames}";
        }

        return null;
    }

    /**
     * Validate AMI against available images.
     *
     * @param array<string, string> $validImages Available AMIs
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateAwsImage(mixed $ami, array $validImages): ?string
    {
        if (!is_string($ami)) {
            return 'AMI must be a string';
        }

        if ('' === trim($ami)) {
            return 'AMI cannot be empty';
        }

        if (!isset($validImages[$ami])) {
            return "Invalid AMI: '{$ami}' is not available in this region";
        }

        return null;
    }

    /**
     * Validate key pair name against available keys.
     *
     * @param array<string, string> $validKeys Available key pairs (name => description)
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateAwsSSHKeyName(mixed $keyName, array $validKeys): ?string
    {
        if (!is_string($keyName)) {
            return 'Key pair name must be a string';
        }

        if ('' === trim($keyName)) {
            return 'Key pair name cannot be empty';
        }

        if (!isset($validKeys[$keyName])) {
            return "Invalid key pair: '{$keyName}' is not available in this region";
        }

        return null;
    }

    /**
     * Validate VPC ID against available VPCs.
     *
     * @param array<string, string> $availableVpcs Available VPCs (ID => description)
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateAwsVPC(mixed $vpcId, array $availableVpcs): ?string
    {
        if (!is_string($vpcId)) {
            return 'VPC ID must be a string';
        }

        if ('' === trim($vpcId)) {
            return 'VPC ID cannot be empty';
        }

        // Validate VPC ID format (vpc-...)
        if (!preg_match('/^vpc-[a-f0-9]+$/i', $vpcId)) {
            return 'VPC ID must be in format vpc-xxxxxxxxx';
        }

        if (!isset($availableVpcs[$vpcId])) {
            return "Invalid VPC: '{$vpcId}' not found in this region";
        }

        return null;
    }

    /**
     * Validate subnet ID against available subnets.
     *
     * @param array<string, string> $availableSubnets Available subnets (ID => description)
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateAwsSubnet(mixed $subnetId, array $availableSubnets): ?string
    {
        if (!is_string($subnetId)) {
            return 'Subnet ID must be a string';
        }

        if ('' === trim($subnetId)) {
            return 'Subnet ID cannot be empty';
        }

        // Validate subnet ID format (subnet-...)
        if (!preg_match('/^subnet-[a-f0-9]+$/i', $subnetId)) {
            return 'Subnet ID must be in format subnet-xxxxxxxxx';
        }

        if (!isset($availableSubnets[$subnetId])) {
            return "Invalid subnet: '{$subnetId}' not found in the selected VPC";
        }

        return null;
    }

    /**
     * Validate key selection exists in available keys.
     *
     * @param array<string, string> $validKeys Available key pairs (name => description)
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateAwsKeySelection(mixed $keyName, array $validKeys): ?string
    {
        if (!is_string($keyName)) {
            return 'Key pair name must be a string';
        }

        if ('' === trim($keyName)) {
            return 'Key pair name cannot be empty';
        }

        if (!isset($validKeys[$keyName])) {
            return 'EC2 key pair not found';
        }

        return null;
    }

    /**
     * Validate disk size input.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateAwsDiskSize(mixed $size): ?string
    {
        if (!is_string($size) && !is_int($size)) {
            return 'Disk size must be a number';
        }

        $sizeInt = is_string($size) ? (int) $size : $size;

        if (8 > $sizeInt) {
            return 'Disk size must be at least 8 GB';
        }

        if (16384 < $sizeInt) {
            return 'Disk size cannot exceed 16384 GB (16 TB)';
        }

        return null;
    }

}
