<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Services;

use GuzzleHttp\Client;

/**
 * HTTP client service.
 *
 * Handles HTTP requests and URL verification.
 */
class HttpService
{
    private readonly Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 10,
            'http_errors' => false,
        ]);
    }

    /**
     * Verify URL responds with expected status and content.
     *
     * @return array{success: bool, status_code: int, body: string}
     */
    public function verifyUrl(string $url): array
    {
        $response = $this->client->get($url);

        return [
            'success' => $response->getStatusCode() === 200,
            'status_code' => $response->getStatusCode(),
            'body' => (string) $response->getBody(),
        ];
    }
}
