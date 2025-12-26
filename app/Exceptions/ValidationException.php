<?php

declare(strict_types=1);

namespace DeployerPHP\Exceptions;

/**
 * Thrown when user input validation fails.
 *
 * Used by IoService::getValidatedOptionOrPrompt() to signal validation failure.
 * Commands should catch this exception and display the error message.
 */
class ValidationException extends \RuntimeException
{
}
