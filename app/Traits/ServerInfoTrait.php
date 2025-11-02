<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Traits;

use Bigpixelrocket\DeployerPHP\DTOs\ServerDTO;
use Bigpixelrocket\DeployerPHP\Services\IOService;

/**
 * Reusable helpers for gathering and interpreting server information.
 *
 * Requires classes using this trait to have IOService property and use PlaybookHelpersTrait.
 *
 * @property IOService $io
 */
trait ServerInfoTrait
{
    use PlaybookHelpersTrait;

    /**
     * Get server information by executing server-info playbook.
     *
     * @return array<string, mixed>|int Returns parsed server info or failure code on failure
     */
    protected function getServerInfo(ServerDTO $server): array|int
    {
        return $this->executePlaybook(
            $server,
            'server-info',
            'Gathering server information...'
        );
    }

    /**
     * Display formatted server information.
     *
     * @param array<string, mixed> $info
     */
    protected function displayServerInfo(array $info): void
    {
        $distroName = match ($info['distro'] ?? 'unknown') {
            'debian' => 'Debian/Ubuntu',
            'redhat' => 'RedHat/CentOS/Fedora',
            'amazon' => 'Amazon Linux',
            default => 'Unknown',
        };

        $permissionsText = match ($info['permissions'] ?? 'none') {
            'root' => 'root',
            'sudo' => 'sudo',
            default => 'insufficient',
        };

        $deets = [
            'Distro' => $distroName,
            'User' => $permissionsText,
        ];

        $this->io->displayDeets($deets);
        $this->io->writeln('');

        $services = [];

        // Add listening ports if any
        if (isset($info['ports']) && is_array($info['ports']) && count($info['ports']) > 0) {
            $portsList = [];
            foreach ($info['ports'] as $port => $process) {
                if (is_numeric($port) && is_string($process)) {
                    $portsList[] = "Port {$port}: {$process}";
                }
            }
            if (count($portsList) > 0) {
                $services = $portsList;
            }
        }

        $this->io->displayDeets(['Services' => $services]);
        $this->io->writeln('');
    }
}
