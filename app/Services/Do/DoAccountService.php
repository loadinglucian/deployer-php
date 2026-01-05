<?php

declare(strict_types=1);

namespace DeployerPHP\Services\Do;

use DeployerPHP\Enums\Distribution;
use DigitalOceanV2\Entity\Image as ImageEntity;
use DigitalOceanV2\Entity\Region as RegionEntity;
use DigitalOceanV2\Entity\Size as SizeEntity;

/**
 * DigitalOcean account data service.
 *
 * Handles fetching account-level resources: regions, sizes, images, VPCs, SSH keys.
 */
class DoAccountService extends BaseDoService
{
    //
    // Account data retrieval
    // ----

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

            asort($options);

            return $options;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to fetch regions: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get available droplet sizes, optionally filtered by region.
     *
     * @param string|null $region Region slug to filter sizes by availability
     *
     * @return array<string, string> Array of size slug => description
     */
    public function getAvailableSizes(?string $region = null): array
    {
        $client = $this->getAPI();

        try {
            $sizeApi = $client->size();
            $sizes = $sizeApi->getAll();

            $sizeData = [];
            foreach ($sizes as $size) {
                /** @var SizeEntity $size */
                if (!$size->available) {
                    continue;
                }

                // Filter by region if specified
                if (null !== $region && !in_array($region, $size->regions, true)) {
                    continue;
                }

                $vcpus = $size->vcpus;
                $memory = $size->memory / 1024; // Convert MB to GB
                $disk = $size->disk;
                $price = $size->priceMonthly;

                $sizeData[] = [
                    'slug' => $size->slug,
                    'price' => $price,
                    'label' => "{$size->slug} - {$vcpus} vCPU, {$memory}GB RAM, {$disk}GB SSD (\${$price}/mo)",
                ];
            }

            // Sort by price ascending
            usort($sizeData, fn (array $a, array $b) => $a['price'] <=> $b['price']);

            $options = [];
            foreach ($sizeData as $data) {
                $options[$data['slug']] = $data['label'];
            }

            return $options;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to fetch sizes: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get available OS images (filtered to latest 2 versions per distribution).
     *
     * @return array<string, string> Array of image slug => description
     */
    public function getAvailableImages(): array
    {
        $client = $this->getAPI();

        try {
            $imageApi = $client->image();
            $images = $imageApi->getAll(['type' => 'distribution']);

            // Parse and collect all valid images
            $ubuntu = [];
            $debian = [];

            foreach ($images as $image) {
                /** @var ImageEntity $image */
                $parsed = $this->parseValidImage($image);
                if (null === $parsed) {
                    continue;
                }

                [$distribution, $slug, $version] = $parsed;

                if (Distribution::UBUNTU === $distribution) {
                    $ubuntu[$version] = ['slug' => $slug, 'version' => $version];
                } else {
                    $debian[$version] = ['slug' => $slug, 'version' => $version];
                }
            }

            // Sort versions descending and limit to latest 2 each
            // Cast to string because PHP converts numeric keys like "12" to int
            uksort($ubuntu, fn ($a, $b) => version_compare((string) $b, (string) $a));
            uksort($debian, fn ($a, $b) => version_compare((string) $b, (string) $a));

            $ubuntu = array_slice($ubuntu, 0, 2, true);
            $debian = array_slice($debian, 0, 2, true);

            // Build options with formatted display
            $options = [];

            foreach ($ubuntu as $data) {
                $options[$data['slug']] = Distribution::UBUNTU->formatVersion($data['version']);
            }
            foreach ($debian as $data) {
                $options[$data['slug']] = Distribution::DEBIAN->formatVersion($data['version']);
            }

            asort($options);

            return $options;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to fetch images: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Parse and validate an image entity.
     *
     * @return array{0: Distribution, 1: string, 2: string}|null Returns [Distribution, slug, version] or null
     */
    private function parseValidImage(ImageEntity $image): ?array
    {
        if ('available' !== $image->status || true !== $image->public) {
            return null;
        }

        $slug = $image->slug;
        if (null === $slug || '' === $slug) {
            return null;
        }

        // Ubuntu LTS: ubuntu-24-04-x64 (only xx.04 versions)
        if (preg_match('/^ubuntu-(\d+)-04/', $slug, $matches)) {
            return [Distribution::UBUNTU, $slug, $matches[1] . '.04'];
        }

        // Debian: debian-12-x64
        if (preg_match('/^debian-(\d+)/', $slug, $matches)) {
            return [Distribution::DEBIAN, $slug, $matches[1]];
        }

        return null;
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
