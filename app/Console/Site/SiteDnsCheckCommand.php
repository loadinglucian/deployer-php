<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Site;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\Traits\SitesTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'site:dns:check',
    description: 'Resolve a site\'s DNS records using Google Public DNS'
)]
class SiteDnsCheckCommand extends BaseCommand
{
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

        $this->h1('Check DNS');

        $site = $this->selectSiteDeets();
        if (is_int($site)) {
            return $site;
        }

        $wwwDomain = 'www.' . $site->domain;

        try {
            /** @var array{ipv4: array<int, string>, ipv6: array<int, string>} $apexIps */
            $apexIps = $this->io->promptSpin(
                fn () => $this->http->resolveGoogleIps($site->domain),
                "Resolving DNS for '{$site->domain}'..."
            );

            /** @var array{ipv4: array<int, string>, ipv6: array<int, string>} $wwwIps */
            $wwwIps = $this->io->promptSpin(
                fn () => $this->http->resolveGoogleIps($wwwDomain),
                "Resolving DNS for '{$wwwDomain}'..."
            );
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        $this->displayDnsDeets($site->domain, $apexIps);

        if (0 < count($wwwIps['ipv4']) || 0 < count($wwwIps['ipv6'])) {
            $this->displayDnsDeets($wwwDomain, $wwwIps);
        } else {
            $this->info("No resolved IPs found for '{$wwwDomain}'");
        }

        $this->commandReplay([
            'domain' => $site->domain,
        ]);

        return Command::SUCCESS;
    }

    // ----
    // Helpers
    // ----

    /**
     * @param array{ipv4: array<int, string>, ipv6: array<int, string>} $ips
     */
    private function displayDnsDeets(string $domain, array $ips): void
    {
        $this->displayDeets([
            'Domain' => $domain,
            'A' => $this->formatIps($ips['ipv4']),
            'AAAA' => $this->formatIps($ips['ipv6']),
        ]);

        $this->out('───');
    }

    /**
     * @param array<int, string> $ips
     */
    private function formatIps(array $ips): string
    {
        return [] === $ips ? 'None' : implode(', ', $ips);
    }
}
