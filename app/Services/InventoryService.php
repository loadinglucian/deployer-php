<?php

declare(strict_types=1);

namespace Deployer\Services;

use Symfony\Component\Yaml\Yaml;

/**
 * Inventory file CRUD operations.
 *
 * @example
 * // Store values using dot notation
 * $inventory->set('widgets.alpha.color', 'blue');
 * $inventory->set('widgets.alpha.size', 'large');
 *
 * // Or set entire object at once
 * $inventory->set('widgets.alpha', ['color' => 'blue', 'size' => 'large']);
 *
 * // Retrieve values at any depth
 * $inventory->get('widgets.alpha.color');  // 'blue'
 * $inventory->get('widgets.alpha');        // ['color' => 'blue', 'size' => 'large']
 * $inventory->get('widgets');              // ['alpha' => ['color' => 'blue', 'size' => 'large']]
 *
 * // Default values when path doesn't exist
 * $inventory->get('widgets.beta');                 // null
 * $inventory->get('widgets.beta', []);             // []
 * $inventory->get('widgets.beta.color', 'red');    // 'red'
 *
 * // Delete path
 * $inventory->delete('widgets.alpha');
 */
class InventoryService
{
    /** @var array<string, mixed> */
    private array $inventory = [];

    private ?string $inventoryPath = null;

    private ?string $inventoryFileStatus = null;

    public function __construct(
        private readonly FilesystemService $fs,
    ) {
    }

    //
    // Public
    // ----

    /**
     * Set a value using dot notation path.
     */
    public function set(string $path, mixed $value): void
    {
        $segments = $this->parsePath($path);

        $this->setByPath($this->inventory, $segments, $value);
        $this->writeInventory();
    }

    /**
     * Get a value using dot notation path.
     *
     * @param mixed $default Default value to return if path doesn't exist
     */
    public function get(string $path, mixed $default = null): mixed
    {
        $segments = $this->parsePath($path);
        $value = $this->getByPath($this->inventory, $segments);

        return $value ?? $default;
    }

    /**
     * Delete a value using dot notation path.
     */
    public function delete(string $path): void
    {
        $segments = $this->parsePath($path);

        $this->unsetByPath($this->inventory, $segments);
        $this->writeInventory();
    }

    /**
     * Set a custom inventory path.
     */
    public function setCustomPath(?string $path): void
    {
        $this->inventoryPath = $path;
    }

    /**
     * Load and parse inventory file if it exists.
     */
    public function loadInventoryFile(): void
    {
        $this->inventory = [];

        $path = $this->getInventoryPath();

        // Initialize empty inventory file if it doesn't exist
        if (!$this->fs->exists($path)) {
            $this->inventoryFileStatus = "Creating inventory file at {$path}";
            $this->writeInventory();
        }

        $this->readInventory();
        $this->inventoryFileStatus = $path;
    }

    /**
     * Get the status of the inventory file.
     */
    public function getInventoryFileStatus(): ?string
    {
        return $this->inventoryFileStatus;
    }

    //
    // Private
    // ----

    //
    // Dot Notation Helpers

    /**
     * Parse dot notation path into array segments.
     *
     * @return array<int, string>
     */
    private function parsePath(string $path): array
    {
        return explode('.', $path);
    }

    /**
     * Get value from nested array using dot notation path segments.
     *
     * @param array<string, mixed> $data
     * @param array<int, string> $segments
     */
    private function getByPath(array $data, array $segments): mixed
    {
        $current = $data;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Set value in nested array using dot notation path segments.
     *
     * @param array<string, mixed> $data
     * @param array<int, string> $segments
     */
    private function setByPath(array &$data, array $segments, mixed $value): void
    {
        $current = &$data;

        foreach ($segments as $segment) {
            if (!is_array($current)) {
                $current = [];
            }

            if (!array_key_exists($segment, $current)) {
                $current[$segment] = [];
            }

            $current = &$current[$segment];
        }

        $current = $value;
    }

    /**
     * Remove path from nested array using dot notation path segments.
     *
     * @param array<string, mixed> $data
     * @param array<int, string> $segments
     */
    private function unsetByPath(array &$data, array $segments): bool
    {
        if (empty($segments)) {
            return false;
        }

        $lastSegment = array_pop($segments);
        $current = &$data;

        // Navigate to parent of target
        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return false; // Path doesn't exist
            }
            $current = &$current[$segment];
        }

        if (!is_array($current) || !array_key_exists($lastSegment, $current)) {
            return false; // Target doesn't exist
        }

        unset($current[$lastSegment]);
        return true;
    }

    //
    // File Operations

    /**
     * Get the resolved inventory path (custom or default).
     */
    private function getInventoryPath(): string
    {
        return $this->inventoryPath ?? rtrim($this->fs->getCwd(), '/') . '/deployer.yml';
    }

    /**
     * Read inventory YAML into internal array.
     *
     * @throws \RuntimeException If file cannot be read or parsed
     */
    private function readInventory(): void
    {
        $path = $this->getInventoryPath();

        try {
            $raw = $this->fs->readFile($path);
            $parsed = Yaml::parse($raw);

            /** @var array<string, mixed> $inventory */
            $inventory = is_array($parsed) ? $parsed : [];
            $this->inventory = $inventory;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Error reading inventory file from {$path}: " . $e->getMessage());
        }
    }

    /**
     * Persist internal inventory to YAML file.
     */
    private function writeInventory(): void
    {
        if (null === $this->inventoryFileStatus) {
            throw new \RuntimeException('Inventory not loaded. Call loadInventoryFile() first.');
        }

        $path = $this->getInventoryPath();

        try {
            $yaml = Yaml::dump($this->inventory, 2, 4, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
            $this->fs->dumpFile($path, $yaml);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Error writing inventory file at {$path}: " . $e->getMessage());
        }
    }
}
