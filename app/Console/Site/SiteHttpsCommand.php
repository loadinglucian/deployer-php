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
        // Validate DNS points to selected server before running Certbot
        // ----

        $dnsValidation = $this->ensureDnsPointsToServer(
            domain: $siteServer->site->domain,
            hasWww: $siteServer->site->hasWww,
            serverHost: $siteServer->server->host
        );

        if (is_int($dnsValidation)) {
            return $dnsValidation;
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

    // ----
    // Helpers
    // ----

    private function ensureDnsPointsToServer(string $domain, bool $hasWww, string $serverHost): ?int
    {
        try {
            $expectedIps = $this->resolveExpectedServerIps($serverHost);
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        $domainsToCheck = [$domain];

        if ($hasWww) {
            $domainsToCheck[] = 'www.' . $domain;
        } else {
            $this->info("Skipping 'www.{$domain}' DNS validation because this site has no WWW alias configured");
        }

        foreach ($domainsToCheck as $domainToCheck) {
            try {
                $resolvedIps = $this->resolveDnsWithRetry($domainToCheck);
            } catch (\RuntimeException $e) {
                $this->nay($e->getMessage());

                return Command::FAILURE;
            }

            if (0 === count($resolvedIps['ipv4']) && 0 === count($resolvedIps['ipv6'])) {
                $this->warn("No A/AAAA records found for '{$domainToCheck}'");
                $this->displayDnsComparisonDeets(
                    domain: $domainToCheck,
                    expectedIps: $expectedIps,
                    resolvedIps: $resolvedIps
                );
                $this->nay("DNS for '{$domainToCheck}' must point to '{$serverHost}' before enabling HTTPS");

                return Command::FAILURE;
            }

            $unexpectedIpv4 = array_values(array_diff($resolvedIps['ipv4'], $expectedIps['ipv4']));
            $unexpectedIpv6 = array_values(array_diff($resolvedIps['ipv6'], $expectedIps['ipv6']));

            if (0 < count($unexpectedIpv4) || 0 < count($unexpectedIpv6)) {
                $this->warn("DNS for '{$domainToCheck}' is not fully pointed at '{$serverHost}'");
                $this->displayDnsComparisonDeets(
                    domain: $domainToCheck,
                    expectedIps: $expectedIps,
                    resolvedIps: $resolvedIps
                );
                $this->nay("Cannot enable HTTPS until '{$domainToCheck}' resolves only to '{$serverHost}'");
                $this->info("Run <|cyan>site:dns:check --domain={$domain}</> after updating DNS");

                return Command::FAILURE;
            }
        }

        return null;
    }

    /**
     * Resolve the expected server IPs from the stored server host.
     *
     * @return array{ipv4: array<int, string>, ipv6: array<int, string>}
     */
    private function resolveExpectedServerIps(string $serverHost): array
    {
        $trimmedHost = trim($serverHost);

        if ('' === $trimmedHost) {
            throw new \RuntimeException('Server host cannot be empty');
        }

        $ipv4 = [];
        $ipv6 = [];

        if (false !== filter_var($trimmedHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $canonicalIpv4 = $this->canonicalizeIp($trimmedHost);
            if (null !== $canonicalIpv4) {
                $ipv4[] = $canonicalIpv4;
            }
        } elseif (false !== filter_var($trimmedHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $canonicalIpv6 = $this->canonicalizeIp($trimmedHost);
            if (null !== $canonicalIpv6) {
                $ipv6[] = $canonicalIpv6;
            }
        } else {
            $resolvedHostIps = $this->resolveDnsWithRetry($trimmedHost);
            $ipv4 = $resolvedHostIps['ipv4'];
            $ipv6 = $resolvedHostIps['ipv6'];
        }

        if (0 === count($ipv4) && 0 === count($ipv6)) {
            throw new \RuntimeException(
                "Could not resolve any A/AAAA records for server host '{$trimmedHost}'"
            );
        }

        return [
            'ipv4' => $ipv4,
            'ipv6' => $ipv6,
        ];
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

        return [
            'ipv4' => $this->normalizeIps($ips['ipv4']),
            'ipv6' => $this->normalizeIps($ips['ipv6']),
        ];
    }

    /**
     * @param array{ipv4: array<int, string>, ipv6: array<int, string>} $expectedIps
     * @param array{ipv4: array<int, string>, ipv6: array<int, string>} $resolvedIps
     */
    private function displayDnsComparisonDeets(string $domain, array $expectedIps, array $resolvedIps): void
    {
        $this->displayDeets([
            'Domain' => $domain,
            'Expected A' => $this->formatIps($expectedIps['ipv4']),
            'Expected AAAA' => $this->formatIps($expectedIps['ipv6']),
            'Resolved A' => $this->formatIps($resolvedIps['ipv4']),
            'Resolved AAAA' => $this->formatIps($resolvedIps['ipv6']),
        ]);
        $this->out('───');
    }

    /**
     * @param array<int, string> $ips
     * @return array<int, string>
     */
    private function normalizeIps(array $ips): array
    {
        $normalized = [];

        foreach ($ips as $ip) {
            $canonical = $this->canonicalizeIp($ip);
            if (null !== $canonical) {
                $normalized[] = $canonical;
            }
        }

        $unique = array_values(array_unique($normalized));
        sort($unique);

        return $unique;
    }

    private function canonicalizeIp(string $ip): ?string
    {
        if (false === filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        $packed = inet_pton($ip);
        if (false === $packed) {
            return null;
        }

        $canonical = inet_ntop($packed);

        return false === $canonical ? null : $canonical;
    }

    /**
     * @param array<int, string> $ips
     */
    private function formatIps(array $ips): string
    {
        return [] === $ips ? 'None' : implode(', ', $ips);
    }
}
