<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Site;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\Enums\WwwMode;
use DeployerPHP\Traits\PlaybooksTrait;
use DeployerPHP\Traits\ServersTrait;
use DeployerPHP\Traits\SitesTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'site:https',
    description: 'Enable HTTPS for a site using Certbot'
)]
class SiteHttpsCommand extends BaseCommand
{
    use PlaybooksTrait;
    use ServersTrait;
    use SitesTrait;

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();

        $this->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Domain name');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Enable HTTPS');

        //
        // Select site and server
        // ----

        $siteServer = $this->selectSiteDeetsWithServer();

        if (is_int($siteServer)) {
            return $siteServer;
        }

        $validationResult = $this->ensureSiteExists($siteServer->server, $siteServer->site);

        if (is_int($validationResult)) {
            return $validationResult;
        }

        //
        // Get site configuration
        // ----

        /** @var array<string, mixed> $serverInfo */
        $serverInfo = $siteServer->server->info ?? [];
        $config = $this->getSiteConfig($serverInfo, $siteServer->site->domain);

        if (!is_array($config) || 'unknown' === $config['php_version']) {
            $this->nay('Site configuration not found. Try running site:create again.');

            return Command::FAILURE;
        }

        /** @var string $wwwMode */
        $wwwMode = $config['www_mode'];

        $validWwwModes = WwwMode::values(includeUnknown: false);

        if (!in_array($wwwMode, $validWwwModes, true)) {
            $this->nay(sprintf(
                "Invalid site WWW mode '%s'. Allowed: %s",
                $wwwMode,
                implode(', ', $validWwwModes)
            ));

            return Command::FAILURE;
        }

        //
        // Check if HTTPS is already enabled
        // ----

        if (true === $config['https_enabled']) {
            $this->info("HTTPS is already enabled for '{$siteServer->site->domain}'");

            $this->commandReplay([
                'domain' => $siteServer->site->domain,
            ]);

            return Command::SUCCESS;
        }

        //
        // Execute playbook
        // ----

        $result = $this->executePlaybookSilently(
            $siteServer,
            'site-https',
            'Enabling HTTPS...',
            [
                'DEPLOYER_WWW_MODE' => $wwwMode,
            ]
        );

        if (is_int($result)) {
            return $result;
        }

        $this->yay('HTTPS enabled successfully');

        //
        // Show command replay
        // ----

        $this->commandReplay([
            'domain' => $siteServer->site->domain,
        ]);

        return Command::SUCCESS;
    }
}
