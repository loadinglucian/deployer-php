<?php

declare(strict_types=1);

namespace Deployer\Console\Site;

use Deployer\Contracts\BaseCommand;
use Deployer\Traits\PlaybooksTrait;
use Deployer\Traits\ServersTrait;
use Deployer\Traits\SitesTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'site:https',
    description: 'Enable HTTPS for a site using Caddy automatic certificates'
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

        //
        // Check if HTTPS is already enabled
        // ----

        if (true === $config['https_enabled']) {
            $this->info("HTTPS is already enabled for '{$siteServer->site->domain}'");

            $this->commandReplay('site:https', [
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
                'DEPLOYER_WWW_MODE' => $config['www_mode'],
            ]
        );

        if (is_int($result)) {
            return $result;
        }

        $this->yay('HTTPS enabled successfully');

        //
        // Show command replay
        // ----

        $this->commandReplay('site:https', [
            'domain' => $siteServer->site->domain,
        ]);

        return Command::SUCCESS;
    }
}
