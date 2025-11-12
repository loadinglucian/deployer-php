<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Console\Server;

use Bigpixelrocket\DeployerPHP\Contracts\BaseCommand;
use Bigpixelrocket\DeployerPHP\Enums\Distribution;
use Bigpixelrocket\DeployerPHP\Traits\PlaybooksTrait;
use Bigpixelrocket\DeployerPHP\Traits\ServersTrait;
use GuzzleHttp\Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'server:install',
    description: 'Install and prepare server for hosting PHP applications'
)]
class ServerInstallCommand extends BaseCommand
{
    use PlaybooksTrait;
    use ServersTrait;

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();

        $this->addOption('server', null, InputOption::VALUE_REQUIRED, 'Server name');
        $this->addOption('php-version', null, InputOption::VALUE_REQUIRED, 'PHP version to install');
        $this->addOption('php-default', null, InputOption::VALUE_NONE, 'Set as default PHP version');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->heading('Install Server');

        //
        // Select server & display details
        // ----

        $server = $this->selectServer();

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
        // Execute installation playbook
        // ----

        $result = $this->executePlaybook(
            $server,
            'server-install',
            'Installing server...',
            [
                'DEPLOYER_DISTRO' => $distro,
                'DEPLOYER_PERMS' => $permissions,
                'DEPLOYER_SERVER_NAME' => $server->name,
            ],
            true
        );

        if (is_int($result)) {
            $this->io->error('Server installation failed');

            return $result;
        }

        $this->yay('Server installed successfully');

        //
        // Install PHP
        // ----

        $phpResult = $this->installPhp($server, $info);

        if (is_int($phpResult)) {
            return $phpResult;
        }

        /** @var array{status: int, php_version: string, php_default: bool} $phpResult */
        $phpVersion = $phpResult['php_version'];
        $phpDefault = $phpResult['php_default'];

        //
        // Setup demo site
        // ----

        /** @var string $permissions */
        $demoResult = $this->executePlaybook(
            $server,
            'demo-site',
            'Setting up demo site...',
            [
                'DEPLOYER_PERMS' => $permissions,
            ],
            true
        );

        if (is_int($demoResult)) {
            $this->io->error('Demo site setup failed');

            return Command::FAILURE;
        }

        $this->yay('Demo site setup successful');

        //
        // Verify installation
        // ----

        $url = 'http://' . $server->host;
        $deployKey = isset($result['deploy_public_key']) && is_string($result['deploy_public_key']) && $result['deploy_public_key'] !== 'unknown'
            ? $result['deploy_public_key']
            : null;

        $verification = $this->io->promptSpin(
            fn () => $this->verifyInstallation($url, $deployKey),
            'Verifying installation...'
        );

        if ($verification['status'] === 'success') {
            $this->yay($verification['message']);
        } else {
            $this->io->warning($verification['message']);
        }

        if ($verification['lines'] !== []) {
            $this->io->writeln($verification['lines']);
        }

        //
        // Show command replay
        // ----

        $this->showCommandReplay('server:install', [
            'server' => $server->name,
            'php-version' => $phpVersion,
            'php-default' => $phpDefault,
        ]);

        return Command::SUCCESS;
    }

    //
    // HTTP Verification
    // ----

    /**
     * Verify demo site is responding with expected content.
     *
     * @return array{status: 'success'|'warning', message: string, lines: array<int, string>}
     */
    private function verifyInstallation(string $url, ?string $deployKey): array
    {
        try {
            $client = new Client([
                'timeout' => 10,
                'http_errors' => false,
            ]);

            $response = $client->get($url);
            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();

            if ($statusCode !== 200) {
                return [
                    'status' => 'warning',
                    'message' => "Demo site returned HTTP {$statusCode} (expected 200)",
                    'lines' => [],
                ];
            }

            if (!str_contains($body, 'hello, world')) {
                return [
                    'status' => 'warning',
                    'message' => 'Demo site is responding but content verification failed',
                    'lines' => [],
                ];
            }

            $nextSteps = [
                'Next steps:',
                '  • Caddy running at <fg=cyan>' . $url . '</>',
                '  • Run <fg=cyan>site:add</> to deploy your first application',
            ];

            if ($deployKey !== null) {
                $nextSteps[] = '  • Add this key to your Git provider (GitHub, GitLab, etc.) to enable deployments:';
                $nextSteps[] = '';
                $nextSteps[] = '<fg=cyan>' . $deployKey . '</>';
            }

            $nextSteps[] = '';

            return [
                'status' => 'success',
                'message' => 'Server installation completed successfully',
                'lines' => $nextSteps,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'warning',
                'message' => 'Could not verify demo site: ' . $e->getMessage(),
                'lines' => [],
            ];
        }
    }

}
