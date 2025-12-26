<?php

declare(strict_types=1);

namespace DeployerPHP\Services;

use Closure;
use DeployerPHP\Exceptions\ValidationException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\password;
use function Laravel\Prompts\pause;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\suggest;
use function Laravel\Prompts\text;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console I/O service.
 *
 * Handles all console input/output operations including prompts, output formatting,
 * and status messages. Must be initialized with a command context before use.
 */
class IoService
{
    private Command $command;
    private InputInterface $input;
    private SymfonyStyle $io;

    /**
     * Initialize the I/O service with command context.
     *
     * Must be called before using any I/O methods.
     */
    public function initialize(Command $command, InputInterface $input, OutputInterface $output): void
    {
        $this->command = $command; // Used to inspect input definitions (like --yes and -y, etc.)
        $this->input = $input;
        $this->io = new SymfonyStyle($input, $output);
    }

    // ----
    // Output Methods
    // ----

    /**
     * Write-out multiple lines.
     *
     * @param string|iterable<string> $lines
     */
    public function out(string|iterable $lines): void
    {
        $writeLines = is_string($lines) ? [$lines] : $lines;
        foreach ($writeLines as &$line) {
            $line = str_replace('<|', '<fg=', $line);

            // Add '▒' character in front of each line, preserving color
            if (preg_match('/^(\s*<[^>]+>)(.*)$/', $line, $matches)) {
                // Preserve existing color tags (like <fg=cyan>)
                $line = $matches[1] . '▒ ' . $matches[2];
            } else {
                $line = '▒ ' . $line;
            }
        }

        unset($line);
        $this->io->write($writeLines, true);
    }

    /**
     * This wrapper for Symfony's ConsoleOutput::write() method.
     *
     * @param string|iterable<string> $messages
     * @param bool $newline Whether to add a newline after the messages
     */
    public function write(string|iterable $messages, bool $newline = false): void
    {
        $this->io->write($messages, $newline);
    }

    // ----
    // Input methods
    // ----

    /**
     * Get raw option value from input.
     *
     * Simple accessor for commands that need to check option values
     * without prompting (e.g., optional configuration).
     */
    public function getOptionValue(string $optionName): mixed
    {
        return $this->input->getOption($optionName);
    }

    /**
     * Get option value or prompt user, with automatic validation.
     *
     * Combines getOptionOrPrompt with validation. The validator is automatically
     * applied to both interactive prompts and CLI options.
     *
     * @param string $optionName The option name to check
     * @param Closure(Closure): mixed $promptCallback Closure that receives validator and returns prompt result
     * @param Closure(mixed): ?string $validator Validation closure that returns error message or null
     *
     * @return mixed The validated value
     *
     * @throws ValidationException When CLI option validation fails
     *
     * @example
     * try {
     *     $name = $this->io->getValidatedOptionOrPrompt(
     *         'name',
     *         fn($validate) => $this->io->promptText(
     *             label: 'Server name:',
     *             validate: $validate
     *         ),
     *         fn($value) => $this->validateNameInput($value)
     *     );
     * } catch (ValidationException $e) {
     *     $this->nay($e->getMessage());
     *     return Command::FAILURE;
     * }
     */
    public function getValidatedOptionOrPrompt(
        string $optionName,
        Closure $promptCallback,
        Closure $validator
    ): mixed {
        // Pass validator to prompt callback
        $value = $this->getOptionOrPrompt(
            $optionName,
            fn () => $promptCallback($validator)
        );

        // Validate if value came from CLI option (prompts already validated)
        if ($this->input->getOption($optionName) !== null) {
            $error = $validator($value);
            if (null !== $error) {
                throw new ValidationException($error);
            }
        }

        return $value;
    }

    /**
     * Get boolean option value or prompt user interactively.
     *
     * For VALUE_NONE flags: returns true if provided, prompts if not
     * For VALUE_NEGATABLE flags: returns true/false if provided, prompts if not
     *
     * @param string $optionName The option name to check
     * @param Closure(): bool $promptCallback Closure that performs the confirm prompt
     */
    public function getBooleanOptionOrPrompt(string $optionName, Closure $promptCallback): bool
    {
        $value = $this->input->getOption($optionName);

        // Build list of option flags to check
        $optionFlags = ['--' . $optionName];

        $optionDefinition = null;
        try {
            $inputDef = $this->command->getDefinition();
            if ($inputDef->hasOption($optionName)) {
                $optionDefinition = $inputDef->getOption($optionName);
                if (null !== $optionDefinition->getShortcut()) {
                    $optionFlags[] = '-' . $optionDefinition->getShortcut();
                }
            }
        } catch (\Throwable) {
            // Ignore errors getting shortcut
        }

        if (null !== $optionDefinition && $optionDefinition->isNegatable()) {
            $optionFlags[] = '--no-' . $optionName;
        }

        $wasProvided = $this->input->hasParameterOption($optionFlags, true);

        if ($wasProvided) {
            return (bool) $value;
        }

        if ($this->input->isInteractive()) {
            return $promptCallback();
        }

        return false;
    }

    //
    // Laravel Prompts Wrappers
    // ----

    /**
     * Prompt for text input.
     *
     * @param string $label The question to display
     * @param string $placeholder Optional placeholder text
     * @param string $default Optional default value
     * @param bool $required Whether input is required
     * @param mixed $validate Optional validation callback
     * @param string $hint Optional hint text
     *
     * @return string The user's input
     */
    public function promptText(
        string $label,
        string $placeholder = '',
        string $default = '',
        bool $required = true,
        mixed $validate = null,
        string $hint = ''
    ): string {
        $normalizedValidate = $this->normalizeStringValidator(
            $validate instanceof Closure ? $validate : null,
            $required
        );

        $result = text(
            label: $label,
            placeholder: $placeholder,
            default: $default,
            required: false,
            validate: $normalizedValidate,
            hint: $hint
        );

        return trim($result);
    }

    /**
     * Prompt for password input.
     *
     * @param string $label The question to display
     * @param string $placeholder Optional placeholder text
     * @param bool $required Whether input is required
     * @param mixed $validate Optional validation callback
     * @param string $hint Optional hint text
     *
     * @return string The user's password input
     */
    public function promptPassword(
        string $label,
        string $placeholder = '',
        bool $required = true,
        mixed $validate = null,
        string $hint = ''
    ): string {
        $normalizedValidate = $this->normalizeStringValidator(
            $validate instanceof Closure ? $validate : null,
            $required,
            false
        );

        return password(
            label: $label,
            placeholder: $placeholder,
            required: false,
            validate: $normalizedValidate,
            hint: $hint
        );
    }

    /**
     * Prompt for yes/no confirmation.
     *
     * @param string $label The question to display
     * @param bool $default Default value (true = yes, false = no)
     * @param string $yes Text for "yes" option
     * @param string $no Text for "no" option
     * @param string $hint Optional hint text
     *
     * @return bool True if confirmed, false otherwise
     */
    public function promptConfirm(
        string $label,
        bool $default = true,
        string $yes = 'Yes',
        string $no = 'No',
        string $hint = ''
    ): bool {
        return confirm(
            label: $label,
            default: $default,
            yes: $yes,
            no: $no,
            hint: $hint
        );
    }

    /**
     * Display a message and wait for user to press Enter.
     *
     * @param string $message The message to display
     *
     * @return bool Always returns a boolean
     */
    public function promptPause(string $message = 'Press enter to continue...'): bool
    {
        return pause($message);
    }

    /**
     * Prompt for single selection from options.
     *
     * @param string $label The question to display
     * @param array<int|string, string> $options Available options
     * @param int|string|null $default Default option key
     * @param int $scroll Number of visible options
     * @param mixed $validate Optional validation callback
     * @param string $hint Optional hint text
     *
     * @return int|string The selected option key
     */
    public function promptSelect(
        string $label,
        array $options,
        int|string|null $default = null,
        int $scroll = 5,
        mixed $validate = null,
        string $hint = ''
    ): int|string {
        return select(
            label: $label,
            options: $options,
            default: $default,
            scroll: $scroll,
            validate: $validate,
            hint: $hint
        );
    }

    /**
     * Prompt for multiple selections from options.
     *
     * @param string $label The question to display
     * @param array<int|string, string> $options Available options
     * @param array<int|string> $default Default selected option keys
     * @param int $scroll Number of visible options
     * @param bool $required Whether at least one selection is required
     * @param mixed $validate Optional validation callback
     * @param string $hint Optional hint text
     *
     * @return array<int|string> The selected option keys
     */
    public function promptMultiselect(
        string $label,
        array $options,
        array $default = [],
        int $scroll = 5,
        bool $required = false,
        mixed $validate = null,
        string $hint = ''
    ): array {
        return multiselect(
            label: $label,
            options: $options,
            default: $default,
            scroll: $scroll,
            required: $required,
            validate: $validate,
            hint: $hint
        );
    }

    /**
     * Prompt with autocomplete suggestions.
     *
     * @param string $label The question to display
     * @param array<string>|Closure $options Available suggestions (array or closure)
     * @param string $placeholder Optional placeholder text
     * @param string $default Optional default value
     * @param int $scroll Number of visible suggestions
     * @param bool $required Whether input is required
     * @param mixed $validate Optional validation callback
     * @param string $hint Optional hint text
     *
     * @return string The user's input
     */
    public function promptSuggest(
        string $label,
        array|Closure $options,
        string $placeholder = '',
        string $default = '',
        int $scroll = 5,
        bool $required = true,
        mixed $validate = null,
        string $hint = ''
    ): string {
        $normalizedValidate = $this->normalizeStringValidator(
            $validate instanceof Closure ? $validate : null,
            $required
        );

        $result = suggest(
            label: $label,
            options: $options,
            placeholder: $placeholder,
            default: $default,
            scroll: $scroll,
            required: false,
            validate: $normalizedValidate,
            hint: $hint
        );

        return trim($result);
    }

    /**
     * Prompt with searchable options.
     *
     * @param string $label The question to display
     * @param Closure $options Closure that accepts search string and returns filtered options
     * @param string $placeholder Optional placeholder text
     * @param int $scroll Number of visible options
     * @param mixed $validate Optional validation callback
     * @param string $hint Optional hint text
     *
     * @return int|string The selected option key
     */
    public function promptSearch(
        string $label,
        Closure $options,
        string $placeholder = '',
        int $scroll = 5,
        mixed $validate = null,
        string $hint = ''
    ): int|string {
        return search(
            label: $label,
            options: $options,
            placeholder: $placeholder,
            scroll: $scroll,
            validate: $validate,
            hint: $hint
        );
    }

    /**
     * Display a loading spinner during long operations.
     *
     * @template T
     *
     * @param Closure(): T $callback Operation to perform
     * @param string $message Message to display
     *
     * @return T Result from the callback
     */
    public function promptSpin(
        Closure $callback,
        string $message = 'Loading...'
    ): mixed {
        // Bypass spinner in test environment to prevent terminal conflicts in parallel execution
        if (defined('PHPUNIT_COMPOSER_INSTALL') || defined('__PEST_RUNNING__')) {
            return $callback();
        }

        return spin(
            callback: $callback,
            message: $message
        );
    }

    // ----
    // Private methods
    // ----

    /**
     * Normalize a validator to handle whitespace for string prompts.
     *
     * Wraps the original validator to:
     * - Reject whitespace-only input when required
     * - Pass trimmed values to the validator (unless $trimValue is false)
     *
     * @param Closure|null $validate Original validator or null
     * @param bool $required Whether input is required
     * @param bool $trimValue Whether to trim before passing to validator (false for passwords)
     *
     * @return Closure|null Wrapped validator or null if no wrapping needed
     */
    private function normalizeStringValidator(?Closure $validate, bool $required, bool $trimValue = true): ?Closure
    {
        if (!$required && null === $validate) {
            return null;
        }

        return function (mixed $value) use ($validate, $required, $trimValue): ?string {
            if (!is_string($value)) {
                /** @var ?string */
                return null !== $validate ? $validate($value) : null;
            }

            $trimmed = trim($value);

            // Reject whitespace-only when required
            if ($required && '' === $trimmed) {
                return 'This field is required.';
            }

            // Pass trimmed (or original for passwords) to validator
            if (null !== $validate) {
                /** @var ?string */
                return $validate($trimValue ? $trimmed : $value);
            }

            return null;
        };
    }

    /**
     * Get option value or prompt user interactively.
     *
     * Internal method used by getValidatedOptionOrPrompt and getBooleanOptionOrPrompt.
     *
     * @template T
     *
     * @param string $optionName The option name to check
     * @param Closure(): T $promptCallback Closure that performs the actual prompting
     *
     * @return string|bool|T The option value or prompted input
     */
    private function getOptionOrPrompt(
        string $optionName,
        Closure $promptCallback
    ): mixed {
        $value = $this->input->getOption($optionName);

        // For boolean flags (VALUE_NONE or VALUE_NEGATABLE), check if actually provided
        if (is_bool($value)) {
            // Build list of option flags to check
            $optionFlags = ['--' . $optionName];

            // Try to find short flag from option definition
            $optionDefinition = null;
            try {
                $inputDef = $this->command->getDefinition();
                if ($inputDef->hasOption($optionName)) {
                    $optionDefinition = $inputDef->getOption($optionName);
                    if ($optionDefinition->getShortcut() !== null) {
                        $optionFlags[] = '-' . $optionDefinition->getShortcut();
                    }
                }
            } catch (\Throwable) {
                // Ignore errors getting shortcut
            }

            if ($optionDefinition !== null && $optionDefinition->isNegatable()) {
                $optionFlags[] = '--no-' . $optionName;
            }

            // Check if flag was actually provided (works for both CLI and tests with ArrayInput)
            $wasProvided = $this->input->hasParameterOption($optionFlags, true);

            if ($wasProvided) {
                // Flag was provided - return its value (true for CLI flags, could be false in tests)
                return $value;
            }

            // Flag was not provided - prompt in interactive mode, return false otherwise
            if ($this->input->isInteractive()) {
                return $promptCallback();
            }

            return false;
        }

        // Handle string options (including empty strings)
        // null means option was not provided, empty string means it was provided but empty
        if ($value !== null) {
            return $value;
        }

        // Prompt user interactively
        return $promptCallback();
    }
}
