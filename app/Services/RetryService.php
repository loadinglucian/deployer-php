<?php

declare(strict_types=1);

namespace DeployerPHP\Services;

/**
 * Shared retry/backoff service for transient operations.
 */
class RetryService
{
    /**
     * Execute an operation with retry logic and exponential backoff.
     *
     * @template T
     * @param callable(): T $attemptCallback
     * @param callable(\Throwable): bool|null $shouldRetry
     *
     * @return T
     *
     * @throws \RuntimeException If all attempts fail
     */
    public function run(
        callable $attemptCallback,
        string $operationDescription,
        int $retryAttempts = 3,
        int $retryDelaySeconds = 1,
        float $backoffMultiplier = 2.0,
        bool $withJitter = true,
        ?callable $shouldRetry = null
    ): mixed {
        if (1 > $retryAttempts) {
            throw new \RuntimeException('Retry attempts must be at least 1');
        }

        if (0 > $retryDelaySeconds) {
            throw new \RuntimeException('Retry delay must be 0 or greater');
        }

        if (1 > $backoffMultiplier) {
            throw new \RuntimeException('Backoff multiplier must be 1 or greater');
        }

        $attempt = 0;
        $delay = $retryDelaySeconds;
        $lastException = null;

        while ($attempt < $retryAttempts) {
            $attempt++;

            try {
                return $attemptCallback();
            } catch (\Throwable $e) {
                $lastException = $e;

                if (null !== $shouldRetry && !$shouldRetry($e)) {
                    if ($e instanceof \RuntimeException) {
                        throw $e;
                    }

                    throw new \RuntimeException(
                        "Failed to {$operationDescription}: {$e->getMessage()}",
                        previous: $e
                    );
                }
            }

            if ($attempt < $retryAttempts) {
                $this->sleepWithBackoff($delay, $withJitter);
                $delay = (int) ceil($delay * $backoffMultiplier);
            }
        }

        /** @var \Throwable $lastException */
        if (1 < $retryAttempts) {
            throw new \RuntimeException(
                "Failed to {$operationDescription} after {$retryAttempts} attempts",
                previous: $lastException
            );
        }

        if ($lastException instanceof \RuntimeException) {
            throw $lastException;
        }

        throw new \RuntimeException(
            "Failed to {$operationDescription}: {$lastException->getMessage()}",
            previous: $lastException
        );
    }

    /**
     * Sleep for the current backoff delay.
     */
    private function sleepWithBackoff(int $delaySeconds, bool $withJitter): void
    {
        if (0 >= $delaySeconds) {
            return;
        }

        if (!$withJitter) {
            sleep($delaySeconds);
            return;
        }

        $delayMicros = $delaySeconds * 1000000;
        $maxOffsetMicros = intdiv($delayMicros, 2);
        $offsetMicros = random_int(0, $maxOffsetMicros);
        usleep($delayMicros - $offsetMicros);
    }
}
