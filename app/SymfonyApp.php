<?php

declare(strict_types=1);

namespace PHPDeployer;

use PHPDeployer\Console\HelloCommand;
use PHPDeployer\Console\Key\KeyAddDigitalOceanCommand;
use PHPDeployer\Console\Key\KeyDeleteDigitalOceanCommand;
use PHPDeployer\Console\Key\KeyListDigitalOceanCommand;
use PHPDeployer\Console\ScaffoldHooksCommand;
use PHPDeployer\Console\Server\ServerAddCommand;
use PHPDeployer\Console\Server\ServerDeleteCommand;
use PHPDeployer\Console\Server\ServerInfoCommand;
use PHPDeployer\Console\Server\ServerInstallCommand;
use PHPDeployer\Console\Server\ServerListCommand;
use PHPDeployer\Console\Server\ServerLogsCommand;
use PHPDeployer\Console\Server\ServerProvisionDigitalOceanCommand;
use PHPDeployer\Console\Server\ServerRunCommand;
use PHPDeployer\Console\Site\SiteAddCommand;
use PHPDeployer\Console\Site\SiteDeleteCommand;
use PHPDeployer\Console\Site\SiteDeployCommand;
use PHPDeployer\Console\Site\SiteHttpsCommand;
use PHPDeployer\Console\Site\SiteListCommand;
use PHPDeployer\Console\Site\SiteSharedPullCommand;
use PHPDeployer\Console\Site\SiteSharedPushCommand;
use PHPDeployer\Services\VersionService;
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

        $this->io->writeln(
            [
                '',
                '<fg=cyan;options=bold>▒ PHP Deployer</> <fg=bright-blue;options=bold>•</> '.$version.' <fg=magenta;options=bold>•</> Server & Site Deployment for PHP',
                '<fg=cyan;options=bold>▒ ━━━━━━━━━━━━━━━</><fg=bright-blue;options=bold>━━━━━━━━━━━━━━━</><fg=magenta;options=bold>━━━━━━━━━━━━━━━</><fg=gray;options=bold>━━━━━━━━━━━━━━━</>',
            ]
        );
    }

    /**
     * Register commands with auto-wired dependencies.
     */
    private function registerCommands(): void
    {
        $commands = [
            HelloCommand::class,

            //
            // Scaffolding

            ScaffoldHooksCommand::class,

            //
            // Provider key management

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
            SiteSharedPushCommand::class,
            SiteSharedPullCommand::class,
            SiteHttpsCommand::class,
            SiteDeployCommand::class,
        ];

        foreach ($commands as $command) {
            /** @var Command $commandInstance */
            $commandInstance = $this->container->build($command);
            $this->add($commandInstance);
        }
    }
}
