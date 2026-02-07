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
        $shouldCheckWww = $site->hasWww;
        $wwwIps = [
            'ipv4' => [],
            'ipv6' => [],
        ];

        try {
            $apexIps = $this->resolveDnsWithRetry($site->domain);

            if ($shouldCheckWww) {
                $wwwIps = $this->resolveDnsWithRetry($wwwDomain);
            }
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        $this->displayDnsDeets($site->domain, $apexIps);

        if (! $shouldCheckWww) {
            $this->info("Skipping '{$wwwDomain}' lookup because this site has no WWW alias configured");
        } elseif (0 < count($wwwIps['ipv4']) || 0 < count($wwwIps['ipv6'])) {
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

    /**
     * Resolve DNS records for a domain with retry/backoff.
     *
     * @return array{ipv4: array<int, string>, ipv6: array<int, string>}
     */
    private function resolveDnsWithRetry(string $domain): array
    {
        /** @var array{ipv4: array<int, string>, ipv6: array<int, string>} $ips */
        $ips = $this->io->promptSpin(
            fn () => $this->retry->run(
                attemptCallback: fn () => $this->http->resolveGoogleIps($domain),
                operationDescription: "resolve DNS records for '{$domain}' via Google DNS",
                retryAttempts: 4,
                retryDelaySeconds: 1
            ),
            "Resolving DNS for '{$domain}'..."
        );

        return $ips;
    }
}
