<?php

declare(strict_types=1);

namespace DeployerPHP\Services;

use Pdp\Domain;
use Pdp\Rules;
use RuntimeException;

/**
 * Domain classification using the Public Suffix List (PSL).
 */
final class DomainClassifierService
{
    private const PSL_URL = 'https://publicsuffix.org/list/public_suffix_list.dat';
    private const CACHE_DIRECTORY_NAME = 'deployer-php';
    private const CACHE_FILENAME = 'public_suffix_list.dat';
    private const CACHE_TTL_SECONDS = 604800; // 7 days

    private ?Rules $rules = null;

    public function __construct(
        private readonly HttpService $http,
        private readonly FilesystemService $fs,
    ) {
    }

    /**
     * Detect whether a domain includes a subdomain label.
     */
    public function isSubdomain(string $domain): bool
    {
        $hostname = strtolower(trim($domain));
        if ($hostname === '') {
            throw new RuntimeException('Domain cannot be empty when checking subdomain status');
        }

        try {
            $resolved = $this->rules()->resolve(Domain::fromIDNA2008($hostname));
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Unable to classify '{$domain}' using Public Suffix List rules: {$e->getMessage()}",
                previous: $e
            );
        }

        return $resolved->subDomain()->toString() !== '';
    }

    private function rules(): Rules
    {
        if ($this->rules instanceof Rules) {
            return $this->rules;
        }

        $cacheFile = $this->cacheFilePath();
        $cacheDir = $this->fs->getParentDirectory($cacheFile);

        if (! $this->fs->exists($cacheDir)) {
            $this->fs->mkdir($cacheDir, 0700);
        }

        $hasCache = $this->fs->exists($cacheFile);
        $isCacheFresh = false;

        if ($hasCache) {
            $cacheAgeSeconds = time() - $this->fs->getFileModificationTime($cacheFile);
            $isCacheFresh = $cacheAgeSeconds < self::CACHE_TTL_SECONDS;
        }

        if (! $isCacheFresh) {
            $downloaded = $this->downloadPslToCache($cacheFile);

            if (! $downloaded && ! $hasCache) {
                throw new RuntimeException(
                    'Public Suffix List cache is missing and could not be downloaded from publicsuffix.org'
                );
            }
        }

        try {
            $this->rules = Rules::fromPath($cacheFile);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Failed to parse Public Suffix List cache at '{$cacheFile}': {$e->getMessage()}",
                previous: $e
            );
        }

        return $this->rules;
    }

    private function downloadPslToCache(string $cacheFile): bool
    {
        $response = $this->http->verifyUrl(self::PSL_URL);

        if (! $response['success']) {
            return false;
        }

        $body = trim($response['body']);
        if ($body === '' || ! str_contains($body, 'BEGIN ICANN DOMAINS')) {
            return false;
        }

        $this->fs->dumpFile($cacheFile, $body);

        return true;
    }

    private function cacheFilePath(): string
    {
        return $this->fs->joinPaths(
            $this->cacheDirectoryPath(),
            self::CACHE_FILENAME
        );
    }

    private function cacheDirectoryPath(): string
    {
        $userCacheDirectory = $this->fs->getUserCacheDirectory();
        if ($userCacheDirectory !== null) {
            return $this->fs->joinPaths($userCacheDirectory, self::CACHE_DIRECTORY_NAME);
        }

        $tmpDirectory = $this->fs->getTempDirectory();
        $username = get_current_user();

        $safeUsername = preg_replace('/[^A-Za-z0-9._-]/', '_', $username);
        if ($safeUsername === null || $safeUsername === '') {
            $safeUsername = 'user';
        }

        return $this->fs->joinPaths(
            $tmpDirectory,
            sprintf('%s-%s', self::CACHE_DIRECTORY_NAME, $safeUsername)
        );
    }
}
