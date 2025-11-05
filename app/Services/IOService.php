<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Services;

use Closure;

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
class IOService
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

    //
    // Input Gathering
    // -------------------------------------------------------------------------------

    /**
     * Get option value or prompt user interactively.
     *
     * Checks if an option was provided via CLI. If yes, returns it.
     * If not, prompts the user interactively using a custom closure.
     *
     * @template T
     *
     * @param string $optionName The option name to check
     * @param Closure(): T $promptCallback Closure that performs the actual prompting (e.g., text(), select(), confirm())
     *
     * @return string|bool|T The option value or prompted input
     *
     * @example
     * // Text input
     * $name = $this->io->getOptionOrPrompt(
     *     'name',
     *     fn() => $this->io->promptText('Server name:', placeholder: 'web1')
     * );
     *
     * // Boolean flag (VALUE_NONE option)
     * $skip = $this->io->getOptionOrPrompt(
     *     'skip',
     *     fn() => $this->io->promptConfirm('Skip verification?', default: false)
     * );
     *
     * // Select input
     * $env = $this->io->getOptionOrPrompt(
     *     'environment',
     *     fn() => $this->io->promptSelect('Environment:', ['dev', 'staging', 'prod'])
     * );
     */
    public function getOptionOrPrompt(
        string $optionName,
        Closure $promptCallback
    ): mixed {
        $value = $this->input->getOption($optionName);

        // For boolean flags (VALUE_NONE options), check if actually provided
        if (is_bool($value)) {
            // Build list of option flags to check
            $optionFlags = ['--' . $optionName];

            // Try to find short flag from option definition
            try {
                $inputDef = $this->command->getDefinition();
                if ($inputDef->hasOption($optionName)) {
                    $option = $inputDef->getOption($optionName);
                    if ($option->getShortcut() !== null) {
                        $optionFlags[] = '-' . $option->getShortcut();
                    }
                }
            } catch (\Throwable) {
                // Ignore errors getting shortcut
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
     * @return mixed The validated value, or null if validation failed
     *
     * @example
     * $name = $this->io->getValidatedOptionOrPrompt(
     *     'name',
     *     fn($validate) => $this->io->promptText(
     *         label: 'Server name:',
     *         validate: $validate
     *     ),
     *     fn($value) => $this->validateNameInput($value)
     * );
     * if ($name === null) {
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
            if ($error !== null) {
                $this->error($error);
                $this->writeln('');

                return null;
            }
        }

        return $value;
    }

    //
    // Laravel Prompts Wrappers
    // -------------------------------------------------------------------------------

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
        return text(
            label: $label,
            placeholder: $placeholder,
            default: $default,
            required: $required,
            validate: $validate,
            hint: $hint
        );
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
        return password(
            label: $label,
            placeholder: $placeholder,
            required: $required,
            validate: $validate,
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
        return suggest(
            label: $label,
            options: $options,
            placeholder: $placeholder,
            default: $default,
            scroll: $scroll,
            required: $required,
            validate: $validate,
            hint: $hint
        );
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

    //
    // Output Methods
    // -------------------------------------------------------------------------------

    /**
     * Write output without newline.
     *
     * Used for streaming output where content arrives in chunks.
     * For complete lines, use writeln() instead.
     */
    public function write(string $text): void
    {
        $this->io->write($text);
    }

    /**
     * Write-out multiple lines.
     *
     * @param array<int, string> $lines
     */
    public function writeln(string|array $lines): void
    {
        $writeLines = is_array($lines) ? $lines : [$lines];
        foreach ($writeLines as $line) {
            $this->io->writeln(' '.$line);
        }
    }

    /**
     * Display an info message with cyan info symbol.
     */
    public function info(string $message): void
    {
        $this->writeln("<fg=cyan>ℹ {$message}</>");
    }

    /**
     * Display a success message with green checkmark.
     */
    public function success(string $message): void
    {
        $this->writeln("<fg=green>✓ {$message}</>");
    }

    /**
     * Display a warning message with yellow warning symbol.
     */
    public function warning(string $message): void
    {
        $this->writeln("<fg=yellow>⚠ {$message}</>");
    }

    /**
     * Display an error message with red X.
     */
    public function error(string $message): void
    {
        $this->writeln("<fg=red>✗ {$message}</>");
    }

    /**
     * Write-out a heading.
     */
    public function h1(string $text): void
    {
        $this->writeln([
            '<fg=bright-blue>▸ </><fg=cyan;options=bold>'.$text.'</>',
            '',
        ]);
    }

    /**
     * Write-out a separator line.
     */
    public function hr(): void
    {
        $this->writeln([
            '<fg=cyan;options=bold>╭────────</><fg=blue;options=bold>──────────</><fg=bright-blue;options=bold>──────────</><fg=magenta;options=bold>──────────</><fg=gray;options=bold>─────────</>',
            '',
        ]);
    }

    /**
     * Display key-value details with aligned formatting.
     *
     * Formats key-value pairs with proper alignment and gray styling for values.
     *
     * @param array<string, string|int|float|bool|null|array<int, string>> $details Key-value pairs to display
     *
     * @example
     * $this->io->displayDeets([
     *     'Name' => 'production-web-01',
     *     'Host' => '192.168.1.100',
     *     'Port' => 22,
     * ]);
     * // Output:
     * //   Name: production-web-01
     * //   Host: 192.168.1.100
     * //   Port: 22
     */
    public function displayDeets(array $details): void
    {
        if (empty($details)) {
            return;
        }

        // Find longest key for alignment
        $maxLength = max(array_map(strlen(...), array_keys($details)));

        $lines = [];
        foreach ($details as $key => $value) {
            $paddedKey = str_pad($key.':', $maxLength + 1);
            if (is_array($value)) {
                $lines[] = "  {$paddedKey}";
                foreach ($value as $item) {
                    $lines[] = "    <fg=gray>• {$item}</>";
                }
            } else {
                $lines[] = "  {$paddedKey} <fg=gray>{$value}</>";
            }
        }

        $this->writeln($lines);
    }

}
