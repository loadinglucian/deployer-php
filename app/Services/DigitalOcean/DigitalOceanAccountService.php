<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Services\DigitalOcean;

use Bigpixelrocket\DeployerPHP\Enums\Distribution;
use DigitalOceanV2\Entity\Image as ImageEntity;
use DigitalOceanV2\Entity\Region as RegionEntity;
use DigitalOceanV2\Entity\Size as SizeEntity;

/**
 * DigitalOcean account data service.
 *
 * Handles fetching account-level resources: regions, sizes, images, VPCs, SSH keys.
 */
class DigitalOceanAccountService extends BaseDigitalOceanService
{
    //
    // Account data retrieval
    // -------------------------------------------------------------------------------

    /**
     * Get available regions.
     *
     * @return array<string, string> Array of region slug => description
     */
    public function getAvailableRegions(): array
    {
        $client = $this->getAPI();

        try {
            $regionApi = $client->region();
            $regions = $regionApi->getAll();

            $options = [];
            foreach ($regions as $region) {
                /** @var RegionEntity $region */
                if ($region->available) {
                    $options[$region->slug] = "{$region->name} ({$region->slug})";
                }
            }

            return $options;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to fetch regions: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get available droplet sizes.
     *
     * @return array<string, string> Array of size slug => description
     */
    public function getAvailableSizes(): array
    {
        $client = $this->getAPI();

        try {
            $sizeApi = $client->size();
            $sizes = $sizeApi->getAll();

            $options = [];
            foreach ($sizes as $size) {
                /** @var SizeEntity $size */
                if ($size->available) {
                    $vcpus = $size->vcpus;
                    $memory = $size->memory / 1024; // Convert MB to GB
                    $disk = $size->disk;
                    $price = $size->priceMonthly;

                    $options[$size->slug] = "{$size->slug} - {$vcpus} vCPU, {$memory}GB RAM, {$disk}GB SSD (\${$price}/mo)";
                }
            }

            return $options;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to fetch sizes: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get available OS images (filtered to supported distributions).
     *
     * @return array<string, string> Array of image slug => description
     */
    public function getAvailableImages(): array
    {
        $client = $this->getAPI();

        try {
            $imageApi = $client->image();

            // Get distribution images (not snapshots or backups)
            $images = $imageApi->getAll(['type' => 'distribution']);

            $options = [];
            foreach ($images as $image) {
                /** @var ImageEntity $image */
                // Filter to supported distributions only (Debian/Ubuntu)
                if ($image->status === 'available' && $image->public === true) {
                    $distribution = strtolower($image->distribution ?? '');
                    $distEnum = Distribution::tryFrom($distribution);

                    if ($distEnum !== null && $distEnum->isSupported()) {
                        $slug = $image->slug;
                        if ($slug !== null && $slug !== '') {
                            $options[$slug] = "{$image->distribution} {$image->name}";
                        }
                    }
                }
            }

            asort($options);

            return $options;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to fetch images: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get available VPCs for a specific region.
     *
     * @return array<string, string> Array of VPC UUID => name
     */
    public function getUserVpcs(string $region): array
    {
        $client = $this->getAPI();

        try {
            $vpcApi = $client->vpc();
            $vpcs = $vpcApi->getAll();

            $options = ['default' => 'Use default VPC'];
            foreach ($vpcs as $vpc) {
                if ($vpc->region === $region) {
                    $options[$vpc->id] = "{$vpc->name} ({$vpc->id})";
                }
            }

            return $options;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to fetch VPCs: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get available SSH keys.
     *
     * @return array<int, string> Array of key ID => description
     */
    public function getPublicKeys(): array
    {
        $client = $this->getAPI();

        try {
            $keyApi = $client->key();
            $keys = $keyApi->getAll();

            $options = [];
            foreach ($keys as $key) {
                $fingerprint = substr($key->fingerprint, 0, 16) . '...';
                $options[$key->id] = "{$key->name} ({$fingerprint})";
            }

            return $options;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to fetch SSH keys: ' . $e->getMessage(), 0, $e);
        }
    }
}
