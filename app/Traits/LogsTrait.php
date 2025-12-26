<?php

declare(strict_types=1);

namespace DeployerPHP\Traits;

use DeployerPHP\DTOs\ServerDTO;
use DeployerPHP\Services\SshService;

/**
 * Shared utilities for log-viewing commands.
 *
 * @property SshService $ssh
 */
trait LogsTrait
{
    // ----
    // Retrieval
    // ----

    /**
     * Retrieve logs via journalctl.
     *
     * @param string|null $unit Systemd unit name (null for all system logs)
     */
    protected function retrieveJournalLogs(
        ServerDTO $server,
        string $title,
        ?string $unit,
        int $lines
    ): void {
        $this->h2($title);

        try {
            $command = null === $unit
                ? sprintf('journalctl -n %d --no-pager 2>&1', $lines)
                : sprintf('journalctl -u %s -n %d --no-pager 2>&1', escapeshellarg($unit), $lines);

            $result = $this->ssh->executeCommand($server, $command);
            $output = trim((string) $result['output']);

            $noData = '' === $output
                || '-- No entries --' === $output
                || str_contains($output, 'No data available');

            if (0 !== $result['exit_code'] && !$noData) {
                $this->nay("Failed to retrieve {$title} logs");
                $this->io->write($this->highlightErrors($output), true);
                $this->out('───');

                return;
            }

            if ($noData) {
                $this->warn("No {$title} logs found");
            } else {
                $this->io->write($this->highlightErrors($output), true);
            }

            $this->out('───');
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());
        }
    }

    /**
     * Retrieve logs from a file via tail.
     */
    protected function retrieveFileLogs(
        ServerDTO $server,
        string $title,
        string $filepath,
        int $lines
    ): void {
        $this->h2($title);
        $this->out("<|gray>File: {$filepath}</>");

        try {
            $command = sprintf('tail -n %d %s 2>&1', $lines, escapeshellarg($filepath));
            $result = $this->ssh->executeCommand($server, $command);
            $output = trim((string) $result['output']);

            $notFound = str_contains($output, 'No such file')
                || str_contains($output, 'cannot open');

            if ($notFound || '' === $output) {
                $this->warn('No logs found or file does not exist');
            } else {
                $this->io->write($this->highlightErrors($output), true);
            }

            $this->out('───');
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());
        }
    }

    // ----
    // Helpers
    // ----

    /**
     * Highlight error keywords in log content.
     */
    protected function highlightErrors(string $content): string
    {
        $keywords = ['error', 'exception', 'fail', 'failed', 'fatal', 'panic'];
        $statusPattern = '/\b(500|502|503|504)\b/';

        $lines = explode("\n", $content);
        $processed = [];

        foreach ($lines as $line) {
            $lower = strtolower($line);
            $hasError = false;

            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    $hasError = true;
                    break;
                }
            }

            if (!$hasError && preg_match($statusPattern, $line)) {
                $hasError = true;
            }

            $processed[] = $hasError ? "<fg=red>{$line}</>" : $line;
        }

        return implode("\n", $processed);
    }

    // ----
    // Validation
    // ----

    /**
     * Validate line count input for log retrieval.
     */
    protected function validateLineCount(mixed $value): ?string
    {
        if (!is_numeric($value) || (int) $value <= 0) {
            return 'Must be a positive number';
        }

        if ((int) $value > 1000) {
            return 'Cannot exceed 1000 lines';
        }

        return null;
    }
}
