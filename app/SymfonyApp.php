<?php

declare(strict_types=1);

namespace Deployer;

use Deployer\Console\Key\KeyAddDigitalOceanCommand;
use Deployer\Console\Key\KeyDeleteDigitalOceanCommand;
use Deployer\Console\Key\KeyListDigitalOceanCommand;
use Deployer\Console\ScaffoldHooksCommand;
use Deployer\Console\Server\ServerAddCommand;
use Deployer\Console\Server\ServerDeleteCommand;
use Deployer\Console\Server\ServerFirewallCommand;
use Deployer\Console\Server\ServerInfoCommand;
use Deployer\Console\Server\ServerInstallCommand;
use Deployer\Console\Server\ServerListCommand;
use Deployer\Console\Server\ServerLogsCommand;
use Deployer\Console\Server\ServerProvisionDigitalOceanCommand;
use Deployer\Console\Server\ServerRunCommand;
use Deployer\Console\Server\ServerSshCommand;
use Deployer\Console\Site\SiteCreateCommand;
use Deployer\Console\Site\SiteDeleteCommand;
use Deployer\Console\Site\SiteDeployCommand;
use Deployer\Console\Site\SiteHttpsCommand;
use Deployer\Console\Site\SiteListCommand;
use Deployer\Console\Site\SiteSharedPullCommand;
use Deployer\Console\Site\SiteSharedPushCommand;
use Deployer\Console\Site\SiteSshCommand;
use Deployer\Services\VersionService;
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
        $name = 'Deployer';
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

        $this->io->writeln([
            '',
            '<fg=cyan>▒ ▶</> <fg=cyan;options=bold>Deployer</> <fg=cyan>━━━━━━━━━━━━━━━━</><fg=bright-blue>━━━━━━━━━━━━━━━━</><fg=magenta>━━━━━━━━━━━━━━━━</><fg=gray>━━━━━━━━━━━━━━━━━</>',
            '<fg=gray>▒ Ver: '.$version.'</>',
        ]);
    }

    /**
     * Register commands with auto-wired dependencies.
     */
    private function registerCommands(): void
    {
        $commands = [
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
            ServerFirewallCommand::class,
            ServerInfoCommand::class,
            ServerInstallCommand::class,
            ServerListCommand::class,
            ServerLogsCommand::class,
            ServerRunCommand::class,
            ServerSshCommand::class,

            // Providers
            ServerProvisionDigitalOceanCommand::class,

            //
            // Site management

            SiteCreateCommand::class,
            SiteDeleteCommand::class,
            SiteListCommand::class,
            SiteHttpsCommand::class,
            SiteSharedPushCommand::class,
            SiteSharedPullCommand::class,
            SiteDeployCommand::class,
            SiteSshCommand::class,
        ];

        foreach ($commands as $command) {
            /** @var Command $commandInstance */
            $commandInstance = $this->container->build($command);
            $this->add($commandInstance);
        }
    }
}
