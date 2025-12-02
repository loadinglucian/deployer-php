<?php

declare(strict_types=1);

namespace PHPDeployer\Console\Site;

use PHPDeployer\Contracts\BaseCommand;
use PHPDeployer\Traits\PlaybooksTrait;
use PHPDeployer\Traits\ServersTrait;
use PHPDeployer\Traits\SitesTrait;
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
        // Select site
        // ----

        $site = $this->selectSite();

        if (is_int($site)) {
            return $site;
        }

        //
        // Get server for site
        // ----

        $server = $this->getServerForSite($site);

        if (is_int($server)) {
            return $server;
        }

        //
        // Get server info (verifies SSH connection and validates distribution & permissions)
        // ----

        $server = $this->serverInfo($server);

        if (is_int($server) || $server->info === null) {
            return Command::FAILURE;
        }

        [
            'distro' => $distro,
            'permissions' => $permissions,
        ] = $server->info;

        /** @var string $distro */
        /** @var string $permissions */

        //
        // Get site configuration
        // ----

        $config = $this->getSiteConfig($server->info, $site->domain);

        if ($config === null) {
            $this->warn("Site '{$site->domain}' configuration not found on server");
            $this->out([
                '',
                'It looks like this site has not been provisioned yet.',
                'Run <fg=cyan>site:add</> to provision the site first.',
                '',
            ]);

            return Command::SUCCESS;
        }

        if ($config['php_version'] === 'unknown') {
            $this->nay("Could not detect PHP version for '{$site->domain}' from server config; re-provision the site or run server:info to debug.");

            return Command::FAILURE;
        }

        //
        // Execute playbook
        // ----

        $result = $this->executePlaybookSilently(
            $server,
            'site-https',
            'Enabling HTTPS...',
            [
                'DEPLOYER_DISTRO' => $distro,
                'DEPLOYER_PERMS' => $permissions,
                'DEPLOYER_SITE_DOMAIN' => $site->domain,
                'DEPLOYER_PHP_VERSION' => $config['php_version'],
                'DEPLOYER_WWW_MODE' => $config['www_mode'],
            ]
        );

        if (is_int($result)) {
            return $result;
        }

        $this->yay('HTTPS enabled successfully');

        //
        // Display next steps
        // ----

        $displayUrl = ($config['www_mode'] === 'redirect-to-www')
            ? 'https://www.' . $site->domain
            : 'https://' . $site->domain;

        $this->out([
            'Your site is now accessible over HTTPS:',
            '  <fg=cyan>' . $displayUrl . '</>',
            '',
        ]);

        //
        // Show command replay
        // ----

        $this->commandReplay('site:https', [
            'domain' => $site->domain,
        ]);

        return Command::SUCCESS;
    }
}
