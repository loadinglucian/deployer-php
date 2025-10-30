<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\DTOs;

readonly class ServerDTO
{
    public function __construct(
        public string $name,
        public string $host,
        public int $port = 22,
        public string $username = 'root',
        public ?string $privateKeyPath = null,
        public ?string $provider = null,
        public ?int $dropletId = null, // DigitalOcean droplet ID
    ) {
    }
}
