<?php

declare(strict_types=1);

namespace DeployerPHP\Contracts;

use DeployerPHP\Container;
use DeployerPHP\Repositories\ServerRepository;
use DeployerPHP\Repositories\SiteRepository;
use DeployerPHP\Services\AwsService;
use DeployerPHP\Services\DoService;
use DeployerPHP\Services\EnvService;
use DeployerPHP\Services\FilesystemService;
use DeployerPHP\Services\GitService;
use DeployerPHP\Services\HttpService;
use DeployerPHP\Services\InventoryService;
use DeployerPHP\Services\IoService;
use DeployerPHP\Services\ProcessService;
use DeployerPHP\Services\SshService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Base command with shared functionality for all commands.
 *
 * Uses IoService for all console input/output operations.
 * All console commands should extend this class.
 */
abstract class BaseCommand extends Command
{
    /**
     * Create a new BaseCommand with the application's services and repositories.
     *
     * The constructor accepts and stores dependencies (I/O service, environment and inventory services,
     * process service, server/site repositories, SSH service, and the DI container)
     * used by this command and its subclasses.
     */
    public function __construct(
        // Framework
        protected readonly Container $container,

        // Base services
        protected readonly EnvService $env,
        protected readonly FilesystemService $fs,
        protected readonly GitService $git,
        protected readonly HttpService $http,
        protected readonly InventoryService $inventory,
        protected readonly IoService $io,
        protected readonly ProcessService $proc,

        // Servers & sites
        protected readonly ServerRepository $servers,
        protected readonly SiteRepository $sites,
        protected readonly SshService $ssh,

        // Hosting providers
        protected readonly AwsService $aws,
        protected readonly DoService $do,
    ) {
        parent::__construct();
    }

    // ----
    // Configuration
    // ----

    /**
     * Add custom env and inventory options.
     */
    protected function configure(): void
    {
        parent::configure();

        $this->addOption(
            'env',
            null,
            InputOption::VALUE_OPTIONAL,
            'Custom path to .env file (defaults to .env in the current working directory)'
        );

        $this->addOption(
            'inventory',
            null,
            InputOption::VALUE_OPTIONAL,
            'Custom path to deployer.yml file (defaults to deployer.yml in the current working directory)'
        );
    }

    /**
     * Prepare console IO and initialize environment, inventory, and repositories.
     *
     * Initializes the I/O service with command context, applies any custom paths provided
     * via the `--env` and `--inventory` options, loads the corresponding files, and populates
     * the servers and sites repositories from the loaded inventory.
     *
     * @param InputInterface  $input  The current console input.
     * @param OutputInterface $output The current console output.
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        //
        // Initialize I/O service

        $this->io->initialize($this, $input, $output);

        //
        // Initialize env service

        /** @var ?string $customEnvPath */
        $customEnvPath = $input->getOption('env');
        $this->env->setCustomPath($customEnvPath);
        $this->env->loadEnvFile();

        //
        // Initialize inventory service

        /** @var ?string $customInventoryPath */
        $customInventoryPath = $input->getOption('inventory');
        $this->inventory->setCustomPath($customInventoryPath);
        $this->inventory->loadInventoryFile();

        //
        // Initialize repositories

        $this->servers->loadInventory($this->inventory);
        $this->sites->loadInventory($this->inventory);
    }

    // ----
    // Execution
    // ----

    /**
     * Common execution logic.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        //
        // Display env and inventory statuses

        $envStatus = $this->env->getEnvFileStatus();
        $inventoryStatus = $this->inventory->getInventoryFileStatus();

        $this->out([
            "<|gray>Env: {$envStatus}</>",
            "<|gray>Inv: {$inventoryStatus}</>",
        ]);

        return Command::SUCCESS;
    }

    // ----
    // IO Helpers
    // ----

    /**
     * Write-out a separator line.
     */
    protected function hr(): void
    {
        $this->out(
            '────────────────────────────────────────────────────────────────────────────',
        );
    }

    /**
     * Write-out a main heading.
     */
    protected function h1(string $text): void
    {
        $this->out([
            '',
            "# {$text}",
        ]);

        $this->hr();
    }

    /**
     * Write-out a secondary heading.
     */
    protected function h2(string $text): void
    {
        $h2 = "## {$text}";
        $underline = str_repeat('─', strlen($h2));

        $this->out([
            $h2,
            $underline,
        ]);
    }

    /**
     * Display an info message with info symbol.
     */
    protected function info(string $message): void
    {
        $this->out("ℹ {$message}");
    }

    /**
     * Display a message with a checkmark.
     */
    protected function yay(string $message): void
    {
        $this->out("✓ {$message}");
    }

    /**
     * Display a message with a warning symbol.
     */
    protected function warn(string $message): void
    {
        $this->out("! {$message}");
    }

    /**
     * Display a message with an error symbol.
     */
    protected function nay(string $message): void
    {
        $this->out("<|red>✗ {$message}</>");
    }

    /**
     * Display a list of items with a bullet point.
     *
     * @param string|iterable<string> $lines
     */
    protected function ul(string|iterable $lines): void
    {
        $writeLines = is_string($lines) ? [$lines] : $lines;
        foreach ($writeLines as &$line) {
            $line = "• {$line}";
        }
        unset($line);
        $this->out($writeLines);
    }

    /**
     * Display a list of items with numbers.
     *
     * @param string|iterable<string> $lines
     */
    protected function ol(string|iterable $lines): void
    {
        $writeLines = is_string($lines) ? [$lines] : $lines;
        $counter = 1;
        foreach ($writeLines as &$line) {
            $line = "{$counter}. {$line}";
            $counter++;
        }
        unset($line);
        $this->out($writeLines);
    }

    /**
     * This wrapper for Symfony's ConsoleOutput::out() method.
     *
     * @param string|iterable<string> $lines
     */
    protected function out(string|iterable $lines): void
    {
        $this->io->out($lines);
    }

    /**
     * Display key-value details with aligned formatting.
     *
     * Formats key-value pairs with proper alignment and gray styling for values.
     *
     * @param array<int|string, mixed> $details Key-value pairs to display
     * @param bool $ul Whether to use a bullet point list
     */
    protected function displayDeets(array $details, bool $ul = false): void
    {
        if (empty($details)) {
            return;
        }

        // Find longest key for alignment
        $maxLength = max(array_map(fn (int|string $k): int => strlen((string) $k), array_keys($details)));

        foreach ($details as $key => $value) {
            $paddedKey = str_pad($key.':', $maxLength + 1);
            if (is_array($value)) {
                $this->out("{$paddedKey}");
                /** @var array<string, mixed> $value */
                $this->displayDeets($value, true);
                continue;
            }

            /** @var string|int|float|bool|null $value */
            $this->out(($ul ? '• ' : '') . "{$paddedKey} <fg=gray>{$value}</>");
        }
    }

    /**
     * Display a command replay hint showing how to run non-interactively.
     *
     * @param array<string, mixed> $options Array of option name => value pairs
     */
    protected function commandReplay(string $commandName, array $options): void
    {
        //
        // Build command options

        $parts = [];
        $definition = $this->getDefinition();

        foreach ($options as $optionName => $value) {
            $option = $definition->hasOption($optionName) ? $definition->getOption($optionName) : null;

            if (is_bool($value)) {
                if ($option !== null && $option->isNegatable()) {
                    $parts[] = $value ? '--'.$optionName : '--no-'.$optionName;
                } elseif ($value) {
                    $parts[] = '--'.$optionName;
                }

                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            // Format the option
            $optionFlag = '--'.$optionName;
            $stringValue = is_scalar($value) ? (string) $value : '';
            $escapedValue = escapeshellarg($stringValue);
            $parts[] = "{$optionFlag}={$escapedValue}";
        }

        //
        // Display command hint

        $hasParts = count($parts) >= 1;

        $this->io->write([
            '<fg=gray>',
            "\$> vendor/bin/deployer {$commandName} " . ($hasParts ? ' \\' : ''),
        ], true);

        foreach ($parts as $index => $part) {
            $last = $index === count($parts) - 1;
            $suffix = $last ? '' : ' \\';
            $this->io->write(sprintf('  %s%s', $part, $suffix), true);
        }

        $this->io->write('</>');
    }
}
