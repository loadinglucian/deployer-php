<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Console\Site;

use Bigpixelrocket\DeployerPHP\Contracts\BaseCommand;
use Bigpixelrocket\DeployerPHP\Traits\PlaybooksTrait;
use Bigpixelrocket\DeployerPHP\Traits\ServersTrait;
use Bigpixelrocket\DeployerPHP\Traits\SitesTrait;
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

        $this->heading('Enable HTTPS');

        //
        // Select site
        // ----

        $site = $this->selectSite()
        ;
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

        $this->displayServerDeets($server);

        //
        // Get server info (verifies SSH connection and validates distribution & permissions)
        // ----

        $info = $this->serverInfo($server);

        if (is_int($info)) {
            return $info;
        }

        [
            'distro' => $distro,
            'permissions' => $permissions,
        ] = $info;

        /** @var string $distro */
        /** @var string $permissions */

        //
        // Get site configuration
        // ----

        $config = $this->getSiteConfig($info, $site->domain);

        if ($config === null) {
            $this->io->warning("Site '{$site->domain}' configuration not found on server");
            $this->io->writeln([
                '',
                'It looks like this site has not been provisioned yet.',
                'Run <fg=cyan>site:add</> to provision the site first.',
                '',
            ]);

            return Command::SUCCESS;
        }

        $this->io->writeln([
            "  • PHP Version: <fg=cyan>{$config['php_version']}</>",
            "  • WWW Mode: <fg=cyan>{$config['www_mode']}</>",
            '',
        ]);

        //
        // Execute playbook
        // ----

        $result = $this->executePlaybook(
            $server,
            'site-https',
            'Enabling HTTPS...',
            [
                'DEPLOYER_DISTRO' => $distro,
                'DEPLOYER_PERMS' => $permissions,
                'DEPLOYER_SITE_DOMAIN' => $site->domain,
                'DEPLOYER_PHP_VERSION' => $config['php_version'],
                'DEPLOYER_WWW_MODE' => $config['www_mode'],
            ],
            true
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

        $this->io->writeln([
            'Your site is now accessible over HTTPS:',
            '  <fg=cyan>' . $displayUrl . '</>',
            '',
        ]);

        return Command::SUCCESS;
    }
}
