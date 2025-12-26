<?php

declare(strict_types=1);

namespace DeployerPHP\Traits;

use DeployerPHP\DTOs\SiteDTO;
use DeployerPHP\DTOs\SupervisorDTO;
use DeployerPHP\Exceptions\ValidationException;
use DeployerPHP\Services\IoService;
use Symfony\Component\Console\Command\Command;

/**
 * Reusable supervisor program helpers.
 *
 * Requires classes using this trait to have IoService property and output methods.
 *
 * @property IoService $io
 *
 * @method void info(string $message)
 * @method void ul(string|iterable<int|string, string> $lines)
 * @method void nay(string $message)
 * @method void warn(string $message)
 * @method void out(string|iterable<int|string, string> $lines)
 * @method void displayDeets(array<int|string, mixed> $details, bool $ul = false)
 */
trait SupervisorsTrait
{
    // ----
    // Helpers
    // ----

    //
    // UI
    // ----

    /**
     * Display supervisor details.
     */
    protected function displaySupervisorDeets(SupervisorDTO $supervisor): void
    {
        $this->displayDeets([
            'Program' => $supervisor->program,
            'Script' => $supervisor->script,
            'Autostart' => $supervisor->autostart ? 'yes' : 'no',
            'Autorestart' => $supervisor->autorestart ? 'yes' : 'no',
            'Stopwaitsecs' => $supervisor->stopwaitsecs,
            'Numprocs' => $supervisor->numprocs,
        ]);
        $this->out('───');
    }

    /**
     * Display a warning to add a supervisor if no supervisors are available. Otherwise, return all supervisors.
     *
     * @param SiteDTO $site The site containing supervisors
     * @return array<int, SupervisorDTO>|int Returns array of supervisors or Command::SUCCESS if no supervisors available
     */
    protected function ensureSupervisorsAvailable(SiteDTO $site): array|int
    {
        if ([] === $site->supervisors) {
            $this->warn("No supervisor configurations found for '" . $site->domain . "'");
            $this->info('Run <fg=cyan>supervisor:create</> to create one');

            return Command::SUCCESS;
        }

        return $site->supervisors;
    }

    /**
     * Select a supervisor from a site's supervisors by program option or interactive prompt.
     *
     * @param SiteDTO $site The site containing supervisors
     * @return SupervisorDTO|int Returns SupervisorDTO on success, or Command::SUCCESS if no supervisors
     */
    protected function selectSupervisor(SiteDTO $site): SupervisorDTO|int
    {
        $allSupervisors = $this->ensureSupervisorsAvailable($site);

        if (is_int($allSupervisors)) {
            return $allSupervisors;
        }

        //
        // Extract programs and prompt for selection

        $programs = array_map(fn (SupervisorDTO $supervisor) => $supervisor->program, $allSupervisors);

        try {
            /** @var string $program */
            $program = $this->io->getValidatedOptionOrPrompt(
                'program',
                fn ($validate) => $this->io->promptSelect(
                    label: 'Select supervisor program:',
                    options: $programs,
                    validate: $validate
                ),
                fn ($value) => $this->validateSupervisorSelection($value, $allSupervisors)
            );
        } catch (ValidationException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        //
        // Find supervisor by program

        foreach ($allSupervisors as $supervisor) {
            if ($supervisor->program === $program) {
                return $supervisor;
            }
        }

        // Should never reach here due to validation
        return Command::FAILURE;
    }

    // ----
    // Validation
    // ----

    /**
     * Validate numprocs is a positive integer.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateNumprocsInput(mixed $numprocs): ?string
    {
        if (is_string($numprocs)) {
            $numprocs = trim($numprocs);

            if ('' === $numprocs) {
                return 'Number of processes cannot be empty';
            }

            if (! ctype_digit($numprocs)) {
                return 'Number of processes must be a positive integer';
            }

            $numprocs = (int) $numprocs;
        }

        if (! is_int($numprocs)) {
            return 'Number of processes must be a positive integer';
        }

        if ($numprocs < 1) {
            return 'Number of processes must be a positive integer';
        }

        return null;
    }

    /**
     * Validate program name is not empty and contains valid characters.
     *
     * Program names must be non-empty and contain only alphanumeric characters,
     * underscores, and hyphens.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateProgramInput(mixed $program): ?string
    {
        if (! is_string($program)) {
            return 'Program name must be a string';
        }

        $program = trim($program);

        if ('' === $program) {
            return 'Program name cannot be empty';
        }

        // Only allow alphanumeric, underscores, and hyphens
        if (! preg_match('/^[a-zA-Z0-9_-]+$/', $program)) {
            return 'Program name must contain only alphanumeric characters, underscores, and hyphens';
        }

        return null;
    }

    /**
     * Validate stopwaitsecs is a positive integer.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateStopWaitSecsInput(mixed $stopwaitsecs): ?string
    {
        if (is_string($stopwaitsecs)) {
            $stopwaitsecs = trim($stopwaitsecs);

            if ('' === $stopwaitsecs) {
                return 'Stop wait seconds cannot be empty';
            }

            if (! ctype_digit($stopwaitsecs)) {
                return 'Stop wait seconds must be a positive integer';
            }

            $stopwaitsecs = (int) $stopwaitsecs;
        }

        if (! is_int($stopwaitsecs)) {
            return 'Stop wait seconds must be a positive integer';
        }

        if ($stopwaitsecs < 1) {
            return 'Stop wait seconds must be a positive integer';
        }

        return null;
    }

    /**
     * Validate supervisor script exists in available scripts.
     *
     * @param array<int, string> $availableScripts Available scripts from repository
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateSupervisorScriptInput(mixed $script, array $availableScripts): ?string
    {
        if (! is_string($script)) {
            return 'Script must be a string';
        }

        if (! in_array($script, $availableScripts, true)) {
            return "Supervisor script not found: .deployer/supervisors/{$script}";
        }

        return null;
    }

    /**
     * Validate supervisor selection exists for site.
     *
     * @param array<int, \DeployerPHP\DTOs\SupervisorDTO> $supervisors Available supervisors for the site
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateSupervisorSelection(mixed $program, array $supervisors): ?string
    {
        if (! is_string($program)) {
            return 'Program name must be a string';
        }

        foreach ($supervisors as $supervisor) {
            if ($supervisor->program === $program) {
                return null;
            }
        }

        return "Supervisor program '{$program}' not found";
    }
}
