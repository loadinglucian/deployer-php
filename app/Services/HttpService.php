<?php

declare(strict_types=1);

namespace DeployerPHP\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * HTTP client service.
 *
 * Handles HTTP requests and URL verification.
 */
class HttpService
{
    private readonly Client $client;

    /**
     * @param array<string, mixed> $clientConfig Additional Guzzle configuration
     */
    public function __construct(array $clientConfig = [])
    {
        /** @var array<string, mixed> $config */
        $config = array_merge([
            'timeout' => 10,
            'http_errors' => false,
        ], $clientConfig);

        $this->client = new Client($config);
    }

    /**
     * Verify URL responds with expected status and content.
     *
     * @return array{success: bool, status_code: int, body: string}
     */
    public function verifyUrl(string $url): array
    {
        try {
            $response = $this->client->get($url);

            return [
                'success' => $response->getStatusCode() === 200,
                'status_code' => $response->getStatusCode(),
                'body' => (string) $response->getBody(),
            ];
        } catch (GuzzleException $e) {
            return [
                'success' => false,
                'status_code' => 0,
                'body' => $e->getMessage(),
            ];
        }
    }

    /**
     * Resolve IPv4 and IPv6 addresses using Google Public DNS JSON endpoint.
     *
     * @return array{ipv4: array<int, string>, ipv6: array<int, string>}
     */
    public function resolveGoogleIps(string $hostname): array
    {
        $hostname = trim($hostname);

        if ('' === $hostname) {
            throw new RuntimeException('Hostname cannot be empty');
        }

        return [
            'ipv4' => $this->resolveGoogleType($hostname, 'A'),
            'ipv6' => $this->resolveGoogleType($hostname, 'AAAA'),
        ];
    }

    /**
     * Resolve a single DNS record type from Google Public DNS.
     *
     * @param 'A'|'AAAA' $type
     * @return array<int, string>
     */
    private function resolveGoogleType(string $hostname, string $type): array
    {
        try {
            $response = $this->client->get('https://dns.google/resolve', [
                'query' => [
                    'name' => $hostname,
                    'type' => $type,
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                "Failed to resolve {$type} records for '{$hostname}' via Google DNS: {$e->getMessage()}",
                previous: $e
            );
        }

        $statusCode = $response->getStatusCode();
        if (200 !== $statusCode) {
            throw new RuntimeException(
                "Google DNS API returned HTTP {$statusCode} while resolving {$type} records for '{$hostname}'"
            );
        }

        /** @var mixed $decoded */
        $decoded = json_decode((string) $response->getBody(), true);
        if (! is_array($decoded)) {
            throw new RuntimeException(
                "Google DNS API returned invalid JSON while resolving {$type} records for '{$hostname}'"
            );
        }

        /** @var mixed $answers */
        $answers = $decoded['Answer'] ?? [];
        if (! is_array($answers)) {
            return [];
        }

        $unique = [];
        $expectedIpFlag = 'A' === $type ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6;

        foreach ($answers as $answer) {
            if (! is_array($answer)) {
                continue;
            }

            $data = $answer['data'] ?? null;
            if (! is_string($data)) {
                continue;
            }

            if (false === filter_var($data, FILTER_VALIDATE_IP, $expectedIpFlag)) {
                continue;
            }

            if (! isset($unique[$data])) {
                $unique[$data] = true;
            }
        }

        return array_keys($unique);
    }
}
