<?php

declare(strict_types=1);

namespace Deployer\DTOs;

readonly class ServerDTO
{
    /** @param array<string, mixed>|null $info */
    public function __construct(
        public string $name,
        public string $host,
        public int $port = 22,
        public string $username = 'root',
        public ?string $privateKeyPath = null,
        public ?string $provider = null,
        public ?int $dropletId = null, // DigitalOcean droplet ID
        public ?string $instanceId = null, // AWS EC2 instance ID
        public ?array $info = null,
    ) {
    }

    /**
     * Check if this server was provisioned via DigitalOcean.
     */
    public function isDigitalOcean(): bool
    {
        return 'digitalocean' === $this->provider && null !== $this->dropletId;
    }

    /**
     * Check if this server was provisioned via AWS.
     */
    public function isAws(): bool
    {
        return 'aws' === $this->provider && null !== $this->instanceId;
    }

    /**
     * Check if this server was provisioned via a cloud provider.
     */
    public function isProvisioned(): bool
    {
        return $this->isDigitalOcean() || $this->isAws();
    }

    /**
     * Return a new instance with the provided info.
     *
     * @param array<string, mixed> $info
     */
    public function withInfo(array $info): self
    {
        return new self(
            name: $this->name,
            host: $this->host,
            port: $this->port,
            username: $this->username,
            privateKeyPath: $this->privateKeyPath,
            provider: $this->provider,
            dropletId: $this->dropletId,
            instanceId: $this->instanceId,
            info: $info,
        );
    }
}
