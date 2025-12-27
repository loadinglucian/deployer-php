<?php

declare(strict_types=1);

namespace DeployerPHP;

use DeployerPHP\Console\Cron\CronCreateCommand;
use DeployerPHP\Console\Cron\CronDeleteCommand;
use DeployerPHP\Console\Cron\CronSyncCommand;
use DeployerPHP\Console\Mariadb\MariadbInstallCommand;
use DeployerPHP\Console\Mariadb\MariadbLogsCommand;
use DeployerPHP\Console\Mariadb\MariadbRestartCommand;
use DeployerPHP\Console\Mariadb\MariadbStartCommand;
use DeployerPHP\Console\Mariadb\MariadbStopCommand;
use DeployerPHP\Console\Mysql\MysqlInstallCommand;
use DeployerPHP\Console\Mysql\MysqlLogsCommand;
use DeployerPHP\Console\Mysql\MysqlRestartCommand;
use DeployerPHP\Console\Mysql\MysqlStartCommand;
use DeployerPHP\Console\Mysql\MysqlStopCommand;
use DeployerPHP\Console\Postgresql\PostgresqlInstallCommand;
use DeployerPHP\Console\Postgresql\PostgresqlLogsCommand;
use DeployerPHP\Console\Postgresql\PostgresqlRestartCommand;
use DeployerPHP\Console\Postgresql\PostgresqlStartCommand;
use DeployerPHP\Console\Postgresql\PostgresqlStopCommand;
use DeployerPHP\Console\Pro\Aws\KeyAddCommand as AwsKeyAddCommand;
use DeployerPHP\Console\Pro\Aws\KeyDeleteCommand as AwsKeyDeleteCommand;
use DeployerPHP\Console\Pro\Aws\KeyListCommand as AwsKeyListCommand;
use DeployerPHP\Console\Pro\Aws\ProvisionCommand as AwsProvisionCommand;
use DeployerPHP\Console\Pro\Do\KeyAddCommand as DoKeyAddCommand;
use DeployerPHP\Console\Pro\Do\KeyDeleteCommand as DoKeyDeleteCommand;
use DeployerPHP\Console\Pro\Do\KeyListCommand as DoKeyListCommand;
use DeployerPHP\Console\Pro\Do\ProvisionCommand as DoProvisionCommand;
use DeployerPHP\Console\ScaffoldCronsCommand;
use DeployerPHP\Console\ScaffoldHooksCommand;
use DeployerPHP\Console\ScaffoldSupervisorsCommand;
use DeployerPHP\Console\Server\ServerAddCommand;
use DeployerPHP\Console\Server\ServerDeleteCommand;
use DeployerPHP\Console\Server\ServerFirewallCommand;
use DeployerPHP\Console\Server\ServerInfoCommand;
use DeployerPHP\Console\Server\ServerInstallCommand;
use DeployerPHP\Console\Server\ServerLogsCommand;
use DeployerPHP\Console\Server\ServerRunCommand;
use DeployerPHP\Console\Server\ServerSshCommand;
use DeployerPHP\Console\Site\SiteCreateCommand;
use DeployerPHP\Console\Site\SiteDeleteCommand;
use DeployerPHP\Console\Site\SiteDeployCommand;
use DeployerPHP\Console\Site\SiteHttpsCommand;
use DeployerPHP\Console\Site\SiteRollbackCommand;
use DeployerPHP\Console\Site\SiteSharedPullCommand;
use DeployerPHP\Console\Site\SiteSharedPushCommand;
use DeployerPHP\Console\Site\SiteSshCommand;
use DeployerPHP\Console\Supervisor\SupervisorCreateCommand;
use DeployerPHP\Console\Supervisor\SupervisorDeleteCommand;
use DeployerPHP\Console\Supervisor\SupervisorLogsCommand;
use DeployerPHP\Console\Supervisor\SupervisorRestartCommand;
use DeployerPHP\Console\Supervisor\SupervisorStartCommand;
use DeployerPHP\Console\Supervisor\SupervisorStopCommand;
use DeployerPHP\Console\Supervisor\SupervisorSyncCommand;
use DeployerPHP\Services\VersionService;
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
        $name = 'DeployerPHP';
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
            '<fg=cyan>▒ ▶</> <fg=cyan;options=bold>DeployerPHP</> <fg=cyan>━━━━━━━━━━━━━━━━</><fg=bright-blue>━━━━━━━━━━━━━━━━</><fg=magenta>━━━━━━━━━━━━━━━━</><fg=gray>━━━━━━━━━━━━━━━━━</>',
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

            ScaffoldCronsCommand::class,
            ScaffoldHooksCommand::class,
            ScaffoldSupervisorsCommand::class,

            //
            // Server management

            ServerAddCommand::class,
            ServerDeleteCommand::class,
            ServerFirewallCommand::class,
            ServerInfoCommand::class,
            ServerInstallCommand::class,
            ServerLogsCommand::class,
            ServerRunCommand::class,
            ServerSshCommand::class,

            //
            // Provider integrations (keys + provisioning)

            AwsKeyAddCommand::class,
            AwsKeyDeleteCommand::class,
            AwsKeyListCommand::class,
            AwsProvisionCommand::class,
            DoKeyAddCommand::class,
            DoKeyDeleteCommand::class,
            DoKeyListCommand::class,
            DoProvisionCommand::class,

            //
            // Site management

            SiteCreateCommand::class,
            SiteDeleteCommand::class,
            SiteDeployCommand::class,
            SiteHttpsCommand::class,
            SiteRollbackCommand::class,
            SiteSharedPullCommand::class,
            SiteSharedPushCommand::class,
            SiteSshCommand::class,

            //
            // Cron management

            CronCreateCommand::class,
            CronDeleteCommand::class,
            CronSyncCommand::class,

            //
            // Supervisor management

            SupervisorCreateCommand::class,
            SupervisorDeleteCommand::class,
            SupervisorLogsCommand::class,
            SupervisorRestartCommand::class,
            SupervisorStartCommand::class,
            SupervisorStopCommand::class,
            SupervisorSyncCommand::class,

            //
            // MySQL management

            MysqlInstallCommand::class,
            MysqlLogsCommand::class,
            MysqlRestartCommand::class,
            MysqlStartCommand::class,
            MysqlStopCommand::class,

            //
            // MariaDB management

            MariadbInstallCommand::class,
            MariadbLogsCommand::class,
            MariadbRestartCommand::class,
            MariadbStartCommand::class,
            MariadbStopCommand::class,

            //
            // PostgreSQL management

            PostgresqlInstallCommand::class,
            PostgresqlLogsCommand::class,
            PostgresqlRestartCommand::class,
            PostgresqlStartCommand::class,
            PostgresqlStopCommand::class,
        ];

        foreach ($commands as $command) {
            /** @var Command $commandInstance */
            $commandInstance = $this->container->build($command);
            $this->add($commandInstance);
        }
    }
}
