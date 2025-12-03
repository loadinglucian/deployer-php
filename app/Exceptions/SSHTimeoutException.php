<?php

declare(strict_types=1);

namespace Deployer\Exceptions;

/**
 * Exception thrown when SSH command execution times out.
 */
class SSHTimeoutException extends \RuntimeException
{
    public function __construct(
        string $message = 'SSH command timed out',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
