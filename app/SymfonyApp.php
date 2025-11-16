<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP;

use Bigpixelrocket\DeployerPHP\Console\HelloCommand;
use Bigpixelrocket\DeployerPHP\Console\Key\KeyAddDigitalOceanCommand;
use Bigpixelrocket\DeployerPHP\Console\Key\KeyDeleteDigitalOceanCommand;
use Bigpixelrocket\DeployerPHP\Console\Key\KeyListDigitalOceanCommand;
use Bigpixelrocket\DeployerPHP\Console\Server\ServerAddCommand;
use Bigpixelrocket\DeployerPHP\Console\Server\ServerDeleteCommand;
use Bigpixelrocket\DeployerPHP\Console\Server\ServerInfoCommand;
use Bigpixelrocket\DeployerPHP\Console\Server\ServerInstallCommand;
use Bigpixelrocket\DeployerPHP\Console\Server\ServerListCommand;
use Bigpixelrocket\DeployerPHP\Console\Server\ServerLogsCommand;
use Bigpixelrocket\DeployerPHP\Console\Server\ServerProvisionDigitalOceanCommand;
use Bigpixelrocket\DeployerPHP\Console\Server\ServerRunCommand;
use Bigpixelrocket\DeployerPHP\Console\Site\SiteAddCommand;
use Bigpixelrocket\DeployerPHP\Console\Site\SiteDeleteCommand;
use Bigpixelrocket\DeployerPHP\Console\Site\SiteListCommand;
use Bigpixelrocket\DeployerPHP\Services\VersionService;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The Symfony application entry point.
 */
final class SymfonyApp extends SymfonyApplication
{
    private SymfonyStyle $io;

    public function __construct(
        private readonly Container $container,
        private readonly VersionService $versionService,
    ) {
        $name = 'Deployer PHP';
        $version = $this->versionService->getVersion();
        parent::__construct($name, $version);

        $this->registerCommands();

        $this->setDefaultCommand('list');
    }

    //
    // Public
    // ----

    /**
     * Override default input definition to remove unwanted options.
     */
    protected function getDefaultInputDefinition(): InputDefinition
    {
        return new InputDefinition([
            new InputArgument('command', InputArgument::OPTIONAL, 'The command to execute'),
            new InputOption('--help', '-h', InputOption::VALUE_NONE, 'Display help for the given command. When no command is given display help for the list command'),
            new InputOption('--version', '-V', InputOption::VALUE_NONE, 'Display this application version'),
            new InputOption('--ansi', '', InputOption::VALUE_NEGATABLE, 'Force (or disable --no-ansi) ANSI output', null),
        ]);
    }

    /**
     * Override to hide default Symfony application name/version display.
     */
    public function getHelp(): string
    {
        return '';
    }

    /**
     * The main execution method in Symfony Console applications.
     */
    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);

        $this->displayBanner();

        // If --version is requested, skip the rest (banner includes version)
        if ($input->hasParameterOption(['--version', '-V'], true)) {
            return Command::SUCCESS;
        }

        return parent::doRun($input, $output);
    }

    //
    // Private
    // ----

    /**
     * Display retro BBS-style ASCII art banner.
     */
    private function displayBanner(): void
    {
        $version = $this->getVersion();

        // Simple, compact banner
        $banner = [
            '',
            '<fg=cyan;options=bold>╭────────</><fg=blue;options=bold>──────────</><fg=bright-blue;options=bold>──────────</><fg=magenta;options=bold>──────────</><fg=gray;options=bold>─────────</>',
            '  <fg=cyan;options=bold>┌┬┐┌─┐┌─┐┬  ┌─┐┬ ┬┌─┐┬─┐</>',
            '  <fg=cyan;options=bold> ││├┤ ├─┘│  │ │└┬┘├┤ ├┬┘</>',
            '  <fg=blue;options=bold>─┴┘└─┘┴  ┴─┘└─┘ ┴ └─┘┴└─PHP</> <fg=bright-blue;options=bold>'.$version.'</>',
            '',
            '  The Server & Site Deployment Tool for PHP',
            '<fg=cyan;options=bold>╰────────</><fg=blue;options=bold>──────────</><fg=bright-blue;options=bold>──────────</><fg=magenta;options=bold>──────────</><fg=gray;options=bold>─────────</>',
            ''
        ];

        // Display the banner
        foreach ($banner as $line) {
            $this->io->writeln($line);
        }
    }

    /**
     * Register commands with auto-wired dependencies.
     */
    private function registerCommands(): void
    {
        $commands = [
            HelloCommand::class,

            //
            // Key management

            KeyAddDigitalOceanCommand::class,
            KeyDeleteDigitalOceanCommand::class,
            KeyListDigitalOceanCommand::class,

            //
            // Server management

            ServerAddCommand::class,
            ServerDeleteCommand::class,
            ServerListCommand::class,
            ServerInfoCommand::class,
            ServerInstallCommand::class,
            ServerLogsCommand::class,
            ServerRunCommand::class,

            // Providers
            ServerProvisionDigitalOceanCommand::class,

            //
            // Site management

            SiteAddCommand::class,
            SiteDeleteCommand::class,
            SiteListCommand::class,
        ];

        foreach ($commands as $command) {
            /** @var Command $commandInstance */
            $commandInstance = $this->container->build($command);
            $this->add($commandInstance);
        }
    }
}
