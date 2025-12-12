<?php

declare(strict_types=1);

namespace Deployer\Traits;

use Deployer\DTOs\CronDTO;
use Deployer\DTOs\SiteDTO;
use Deployer\Services\IOService;
use Symfony\Component\Console\Command\Command;

/**
 * Reusable cron job helpers.
 *
 * Requires classes using this trait to have IOService property and output methods.
 *
 * @property IOService $io
 *
 * @method void info(string $message)
 * @method void ul(string|iterable<int|string, string> $lines)
 * @method void nay(string $message)
 */
trait CronsTrait
{
    // ----
    // Validation
    // ----

    /**
     * Validate cron schedule format.
     *
     * Accepts standard cron expressions (5 fields: minute hour day month weekday).
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateScheduleInput(mixed $schedule): ?string
    {
        if (! is_string($schedule)) {
            return 'Schedule must be a string';
        }

        $schedule = trim($schedule);

        if ('' === $schedule) {
            return 'Schedule cannot be empty';
        }

        // Split into fields
        $fields = preg_split('/\s+/', $schedule);
        if (false === $fields || 5 !== count($fields)) {
            return 'Schedule must have exactly 5 fields (minute hour day month weekday)';
        }

        // Validate each field
        $fieldNames = ['minute', 'hour', 'day', 'month', 'weekday'];
        $fieldRanges = [
            [0, 59, []],                                                           // minute
            [0, 23, []],                                                           // hour
            [1, 31, []],                                                           // day
            [1, 12, ['jan', 'feb', 'mar', 'apr', 'may', 'jun',
                'jul', 'aug', 'sep', 'oct', 'nov', 'dec']],   // month
            [0, 7, ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat']],             // weekday
        ];

        foreach ($fields as $i => $field) {
            [$min, $max, $names] = $fieldRanges[$i];
            $error = $this->validateCronField($field, $min, $max, $names);
            if (null !== $error) {
                return "Invalid " . $fieldNames[$i] . ": " . $error;
            }
        }

        return null;
    }

    /**
     * Validate a single cron field.
     *
     * Accepts: number, range, list, wildcard, or step values.
     *
     * @param string       $field Field value
     * @param int          $min   Minimum allowed value
     * @param int          $max   Maximum allowed value
     * @param array<string> $names Allowed name aliases
     * @return string|null Error message if invalid, null if valid
     */
    private function validateCronField(string $field, int $min, int $max, array $names): ?string
    {
        // Wildcard
        if ('*' === $field) {
            return null;
        }

        // Handle lists (comma-separated)
        if (str_contains($field, ',')) {
            foreach (explode(',', $field) as $part) {
                $error = $this->validateCronField($part, $min, $max, $names);
                if (null !== $error) {
                    return $error;
                }
            }

            return null;
        }

        // Handle step values (*/n or range/n)
        $step = null;
        if (str_contains($field, '/')) {
            $parts = explode('/', $field);
            if (2 !== count($parts)) {
                return "'{$field}' has invalid step syntax";
            }
            [$field, $step] = $parts;

            if (! ctype_digit($step) || (int) $step < 1) {
                return "step value must be a positive integer";
            }
        }

        // Wildcard with step (e.g., */5)
        if ('*' === $field) {
            return null;
        }

        // Handle ranges (n-m)
        if (str_contains($field, '-')) {
            $parts = explode('-', $field);
            if (2 !== count($parts)) {
                return "'{$field}' has invalid range syntax";
            }

            $startError = $this->validateCronValue($parts[0], $min, $max, $names);
            if (null !== $startError) {
                return $startError;
            }

            $endError = $this->validateCronValue($parts[1], $min, $max, $names);
            if (null !== $endError) {
                return $endError;
            }

            return null;
        }

        // Single value
        return $this->validateCronValue($field, $min, $max, $names);
    }

    /**
     * Validate a single cron value.
     *
     * Accepts numeric values or name aliases (case-insensitive).
     *
     * @param string       $value Value to validate
     * @param int          $min   Minimum allowed value
     * @param int          $max   Maximum allowed value
     * @param array<string> $names Allowed name aliases
     * @return string|null Error message if invalid, null if valid
     */
    private function validateCronValue(string $value, int $min, int $max, array $names): ?string
    {
        // Check name aliases (case-insensitive)
        if ([] !== $names && in_array(strtolower($value), $names, true)) {
            return null;
        }

        // Must be numeric
        if (! ctype_digit($value)) {
            $allowed = [] !== $names
                ? "must be {$min}-{$max} or " . implode('/', $names)
                : "must be {$min}-{$max}";

            return "'" . $value . "' " . $allowed;
        }

        $num = (int) $value;
        if ($num < $min || $num > $max) {
            return "'" . $value . "' is out of range (" . $min . "-" . $max . ")";
        }

        return null;
    }

    /**
     * Validate script name is not empty.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateScriptInput(mixed $script): ?string
    {
        if (! is_string($script)) {
            return 'Script name must be a string';
        }

        if ('' === trim($script)) {
            return 'Script name cannot be empty';
        }

        return null;
    }

    // ----
    // Helpers
    // ----

    /**
     * Display cron details.
     */
    protected function displayCronDeets(CronDTO $cron): void
    {
        $this->displayDeets([
            'Script' => $cron->script,
            'Schedule' => $cron->schedule,
        ]);
        $this->out('───');
    }

    /**
     * Display a warning to add a cron if no crons are available. Otherwise, return all crons.
     *
     * @param SiteDTO $site The site containing crons
     * @return array<int, CronDTO>|int Returns array of crons or Command::SUCCESS if no crons available
     */
    protected function ensureCronsAvailable(SiteDTO $site): array|int
    {
        if ([] === $site->crons) {
            $this->warn("No cron jobs found for '" . $site->domain . "' in inventory:");
            $this->info('Run <fg=cyan>cron:create</> to create one');

            return Command::SUCCESS;
        }

        return $site->crons;
    }

    /**
     * Select a cron from a site's crons by script option or interactive prompt.
     *
     * @param SiteDTO $site The site containing crons
     * @return CronDTO|int Returns CronDTO on success, or Command::SUCCESS if no crons, or Command::FAILURE if not found
     */
    protected function selectCron(SiteDTO $site): CronDTO|int
    {
        $allCrons = $this->ensureCronsAvailable($site);

        if (is_int($allCrons)) {
            return $allCrons;
        }

        //
        // Extract scripts and prompt for selection

        $scripts = array_map(fn (CronDTO $cron) => $cron->script, $allCrons);

        $script = (string) $this->io->getOptionOrPrompt(
            'script',
            fn () => $this->io->promptSelect(
                label: 'Select cron job:',
                options: $scripts,
            )
        );

        //
        // Find cron by script

        foreach ($allCrons as $cron) {
            if ($cron->script === $script) {
                return $cron;
            }
        }

        $this->nay("Cron '" . $script . "' not found for '" . $site->domain . "' in inventory");

        return Command::FAILURE;
    }

    /**
     * Scan .deployer/crons/ directory recursively and return sorted list of scripts.
     *
     * @param string $cronsDir Absolute path to crons directory
     * @return array<int, string> Array of script paths relative to crons directory
     */
    protected function scanCronScripts(string $cronsDir): array
    {
        $scripts = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cronsDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                // Get path relative to crons directory
                $relativePath = substr($file->getPathname(), strlen($cronsDir) + 1);
                $scripts[] = $relativePath;
            }
        }

        sort($scripts);

        return $scripts;
    }
}
