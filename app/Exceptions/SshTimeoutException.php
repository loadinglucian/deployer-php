<?php

declare(strict_types=1);

namespace DeployerPHP\Exceptions;

/**
 * Exception thrown when SSH command execution times out.
 */
class SshTimeoutException extends \RuntimeException
{
    public function __construct(
        string $message = 'SSH command timed out',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
