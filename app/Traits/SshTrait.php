<?php

declare(strict_types=1);

namespace DeployerPHP\Traits;

use Symfony\Component\Process\ExecutableFinder;

trait SshTrait
{
    // ----
    // Helpers
    // ----

    /**
     * Find the SSH binary path.
     */
    protected function findSshBinary(): ?string
    {
        $finder = new ExecutableFinder();

        return $finder->find('ssh');
    }
}
