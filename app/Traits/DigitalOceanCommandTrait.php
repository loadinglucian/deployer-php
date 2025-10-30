<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Traits;

use Bigpixelrocket\DeployerPHP\Services\DigitalOceanService;
use Bigpixelrocket\DeployerPHP\Services\EnvService;
use Bigpixelrocket\DeployerPHP\Services\IOService;
use Symfony\Component\Console\Command\Command;

/**
 * Common DigitalOcean actions trait for commands.
 *
 * Requires classes using this trait to have DigitalOceanService, EnvService, and IOService properties.
 *
 * @property DigitalOceanService $digitalOcean
 * @property EnvService $env
 * @property IOService $io
 */
trait DigitalOceanCommandTrait
{
    /**
     * Initialize DigitalOcean API with token from environment.
     *
     * Retrieves the DigitalOcean API token from environment variables
     * (DIGITALOCEAN_API_TOKEN or DO_API_TOKEN), configures the
     * DigitalOcean service, and verifies authentication with a lightweight
     * API call. Displays error messages and exits on failure.
     *
     * @return int Command::SUCCESS on success, Command::FAILURE on error
     */
    protected function initializeDigitalOceanAPI(): int
    {
        try {
            $apiToken = $this->env->get(['DIGITALOCEAN_API_TOKEN', 'DO_API_TOKEN']);

            if ($apiToken === null || $apiToken === '') {
                throw new \InvalidArgumentException('DigitalOcean API token not found in environment');
            }

            // Initialize DigitalOcean API
            $this->io->promptSpin(
                fn () => $this->digitalOcean->initialize($apiToken),
                'Initializing DigitalOcean API...'
            );

            return Command::SUCCESS;
        } catch (\InvalidArgumentException) {
            // Token configuration issue
            $this->io->error('DigitalOcean API token not found in environment.');
            $this->io->writeln('');
            $this->io->writeln('Set DIGITALOCEAN_API_TOKEN or DO_API_TOKEN in your .env file.');
            $this->io->writeln('');

            return Command::FAILURE;
        } catch (\RuntimeException $e) {
            // API authentication failure
            $this->io->error($e->getMessage());
            $this->io->writeln('');
            $this->io->writeln('Check that your API token is valid and has not expired.');
            $this->io->writeln('');

            return Command::FAILURE;
        }
    }
}
