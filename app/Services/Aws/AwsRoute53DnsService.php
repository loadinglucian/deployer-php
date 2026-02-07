<?php

declare(strict_types=1);

namespace DeployerPHP\Services\Aws;

/**
 * AWS Route53 DNS record service.
 *
 * Handles DNS record CRUD operations using Route53.
 */
class AwsRoute53DnsService extends BaseAwsService
{
    /** @var array<int, string> Supported DNS record types */
    public const RECORD_TYPES = ['A', 'AAAA', 'CNAME'];

    //
    // Record retrieval
    // ----

    /**
     * List DNS records for a hosted zone.
     *
     * @param string $zoneId Hosted zone ID
     * @param string|null $type Optional record type filter
     *
     * @return array<int, array{type: string, name: string, value: string, ttl: int, is_alias: bool}>
     */
    public function listRecords(string $zoneId, ?string $type = null): array
    {
        $route53 = $this->createRoute53Client();

        try {
            $params = ['HostedZoneId' => $zoneId];

            $records = [];
            $nextRecordName = null;
            $nextRecordType = null;

            do {
                if (null !== $nextRecordName) {
                    $params['StartRecordName'] = $nextRecordName;
                    $params['StartRecordType'] = $nextRecordType;
                }

                $result = $route53->listResourceRecordSets($params);
                /** @var array<int, mixed> $recordSets */
                $recordSets = $result->get('ResourceRecordSets') ?? [];

                /** @var array<string, mixed> $recordSet */
                foreach ($recordSets as $recordSet) {
                    /** @var string $recordType */
                    $recordType = (string) ($recordSet['Type'] ?? '');
                    /** @var string $recordName */
                    $recordName = (string) ($recordSet['Name'] ?? '');

                    // Filter by type if specified
                    if (null !== $type && $recordType !== $type) {
                        continue;
                    }

                    // Skip SOA records (system-managed)
                    if ('SOA' === $recordType) {
                        continue;
                    }

                    // Handle alias records (no ResourceRecords)
                    if (isset($recordSet['AliasTarget'])) {
                        /** @var array<string, mixed> $aliasTarget */
                        $aliasTarget = $recordSet['AliasTarget'];
                        /** @var string $aliasDnsName */
                        $aliasDnsName = (string) ($aliasTarget['DNSName'] ?? '');
                        $records[] = [
                            'type' => $recordType,
                            'name' => rtrim($recordName, '.'),
                            'value' => $aliasDnsName,
                            'ttl' => 0,
                            'is_alias' => true,
                        ];

                        continue;
                    }

                    // Handle standard records
                    /** @var array<int, mixed> $resourceRecords */
                    $resourceRecords = $recordSet['ResourceRecords'] ?? [];
                    /** @var int $ttl */
                    $ttl = (int) ($recordSet['TTL'] ?? 300);
                    /** @var array<string, mixed> $resourceRecord */
                    foreach ($resourceRecords as $resourceRecord) {
                        /** @var string $recordValue */
                        $recordValue = (string) ($resourceRecord['Value'] ?? '');
                        $records[] = [
                            'type' => $recordType,
                            'name' => rtrim($recordName, '.'),
                            'value' => $recordValue,
                            'ttl' => $ttl,
                            'is_alias' => false,
                        ];
                    }
                }

                $nextRecordName = $result->get('NextRecordName');
                $nextRecordType = $result->get('NextRecordType');
            } while ($result->get('IsTruncated'));

            return $records;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to list DNS records: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Find a specific DNS record by type and name.
     *
     * @param string $zoneId Hosted zone ID
     * @param string $type Record type
     * @param string $name Record name (without trailing dot)
     *
     * @return array{type: string, name: string, value: string, ttl: int, is_alias: bool}|null
     */
    public function findRecord(string $zoneId, string $type, string $name): ?array
    {
        $route53 = $this->createRoute53Client();
        $fqdn = $this->ensureTrailingDot($name);

        try {
            $result = $route53->listResourceRecordSets([
                'HostedZoneId' => $zoneId,
                'StartRecordName' => $fqdn,
                'StartRecordType' => $type,
                'MaxItems' => '1',
            ]);

            /** @var array<int, mixed> $recordSets */
            $recordSets = $result->get('ResourceRecordSets') ?? [];

            /** @var array<string, mixed> $recordSet */
            foreach ($recordSets as $recordSet) {
                /** @var string $recordType */
                $recordType = (string) ($recordSet['Type'] ?? '');
                /** @var string $recordName */
                $recordName = (string) ($recordSet['Name'] ?? '');

                if ($recordType === $type && $recordName === $fqdn) {
                    // Handle alias records
                    if (isset($recordSet['AliasTarget'])) {
                        /** @var array<string, mixed> $aliasTarget */
                        $aliasTarget = $recordSet['AliasTarget'];
                        /** @var string $aliasDnsName */
                        $aliasDnsName = (string) ($aliasTarget['DNSName'] ?? '');
                        return [
                            'type' => $recordType,
                            'name' => rtrim($recordName, '.'),
                            'value' => $aliasDnsName,
                            'ttl' => 0,
                            'is_alias' => true,
                        ];
                    }

                    // Return first resource record value
                    /** @var array<int, mixed> $resourceRecords */
                    $resourceRecords = $recordSet['ResourceRecords'] ?? [];
                    if ([] !== $resourceRecords) {
                        /** @var array<string, mixed> $firstRecord */
                        $firstRecord = $resourceRecords[0];
                        /** @var string $recordValue */
                        $recordValue = (string) ($firstRecord['Value'] ?? '');
                        /** @var int $ttl */
                        $ttl = (int) ($recordSet['TTL'] ?? 300);
                        return [
                            'type' => $recordType,
                            'name' => rtrim($recordName, '.'),
                            'value' => $recordValue,
                            'ttl' => $ttl,
                            'is_alias' => false,
                        ];
                    }
                }
            }

            return null;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to find DNS record: " . $e->getMessage(), 0, $e);
        }
    }

    //
    // Record creation/update
    // ----

    /**
     * Create or update a DNS record (upsert).
     *
     * @param string $zoneId Hosted zone ID
     * @param string $type Record type
     * @param string $name Record name (without trailing dot)
     * @param string $value Record value
     * @param int $ttl TTL in seconds
     *
     * @return array{action: 'upserted'}
     */
    public function setRecord(
        string $zoneId,
        string $type,
        string $name,
        string $value,
        int $ttl = 300,
    ): array {
        $route53 = $this->createRoute53Client();
        $fqdn = $this->ensureTrailingDot($name);

        // Format value based on record type
        $formattedValue = $this->formatRecordValue($type, $value);

        try {
            $this->withAwsRetry(
                attemptCallback: fn () => $route53->changeResourceRecordSets([
                    'HostedZoneId' => $zoneId,
                    'ChangeBatch' => [
                        'Changes' => [
                            [
                                'Action' => 'UPSERT',
                                'ResourceRecordSet' => [
                                    'Name' => $fqdn,
                                    'Type' => $type,
                                    'TTL' => $ttl,
                                    'ResourceRecords' => [
                                        ['Value' => $formattedValue],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]),
                operationDescription: "set DNS record {$type} {$name}",
                retryAttempts: 5,
                retryDelaySeconds: 1,
                shouldRetry: fn (\Throwable $e): bool => $this->isRetryableRoute53ChangeException($e),
            );

            return ['action' => 'upserted'];
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to set DNS record: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Delete a DNS record.
     *
     * @param string $zoneId Hosted zone ID
     * @param string $type Record type
     * @param string $name Record name (without trailing dot)
     * @param string $value Record value (required for deletion)
     * @param int $ttl TTL (required for deletion)
     */
    public function deleteRecord(
        string $zoneId,
        string $type,
        string $name,
        string $value,
        int $ttl,
    ): void {
        $route53 = $this->createRoute53Client();
        $fqdn = $this->ensureTrailingDot($name);
        $formattedValue = $this->formatRecordValue($type, $value);

        try {
            $this->withAwsRetry(
                attemptCallback: fn () => $route53->changeResourceRecordSets([
                    'HostedZoneId' => $zoneId,
                    'ChangeBatch' => [
                        'Changes' => [
                            [
                                'Action' => 'DELETE',
                                'ResourceRecordSet' => [
                                    'Name' => $fqdn,
                                    'Type' => $type,
                                    'TTL' => $ttl,
                                    'ResourceRecords' => [
                                        ['Value' => $formattedValue],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]),
                operationDescription: "delete DNS record {$type} {$name}",
                retryAttempts: 5,
                retryDelaySeconds: 1,
                shouldRetry: fn (\Throwable $e): bool => $this->isRetryableRoute53ChangeException($e),
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to delete DNS record: " . $e->getMessage(), 0, $e);
        }
    }

    //
    // Private helpers
    // ----

    /**
     * Ensure a domain name has a trailing dot.
     */
    private function ensureTrailingDot(string $name): string
    {
        return str_ends_with($name, '.') ? $name : $name . '.';
    }

    /**
     * Format record value based on type.
     *
     * CNAME records need trailing dots.
     */
    private function formatRecordValue(string $type, string $value): string
    {
        return match ($type) {
            'CNAME' => $this->ensureTrailingDot($value),
            default => $value,
        };
    }

    private function isRetryableRoute53ChangeException(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return $this->isRetryableAwsException($e)
            || str_contains($message, 'priorrequestnotcomplete');
    }
}
