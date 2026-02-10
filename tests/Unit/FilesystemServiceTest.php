<?php

declare(strict_types=1);

use DeployerPHP\Services\FilesystemService;
use Symfony\Component\Filesystem\Filesystem;

it('resolves os user cache directory with environment defaults', function () {
    withIsolatedFilesystemEnvironment(function (string $customHome, string $customXdg, string $customLocalAppData): void {
        $service = new FilesystemService(new Filesystem());
        $cacheDir = $service->getUserCacheDirectory();

        if (PHP_OS_FAMILY === 'Windows') {
            expect($cacheDir)->toBe($customLocalAppData);
            return;
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            expect($cacheDir)->toBe($customHome . '/Library/Caches');
            return;
        }

        expect($cacheDir)->toBe($customXdg);
    });
});

it('falls back to os-specific cache defaults when primary env vars are missing', function () {
    withIsolatedFilesystemEnvironment(function (string $customHome, string $customXdg, string $customLocalAppData): void {
        $service = new FilesystemService(new Filesystem());

        if (PHP_OS_FAMILY === 'Windows') {
            $customAppData = $customHome . '/app-data';
            (new Filesystem())->mkdir($customAppData);

            putenv('LOCALAPPDATA');
            putenv("APPDATA={$customAppData}");

            expect($service->getUserCacheDirectory())->toBe($customAppData);
            return;
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            putenv('XDG_CACHE_HOME');

            expect($service->getUserCacheDirectory())->toBe($customHome . '/Library/Caches');
            return;
        }

        putenv('XDG_CACHE_HOME');
        expect($service->getUserCacheDirectory())->toBe($customHome . '/.cache');
    });
});

it('returns null cache directory when no user home is available', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        expect(true)->toBeTrue();
        return;
    }

    withIsolatedFilesystemEnvironment(function (): void {
        $service = new FilesystemService(new Filesystem());

        putenv('HOME');
        putenv('USERPROFILE');
        putenv('HOMEDRIVE');
        putenv('HOMEPATH');
        putenv('XDG_CACHE_HOME');

        expect($service->getUserCacheDirectory())->toBeNull();
    });
});

function withIsolatedFilesystemEnvironment(callable $callback): void
{
    $tempRoot = sys_get_temp_dir() . '/deployer-php-fs-test-' . bin2hex(random_bytes(6));
    $symfonyFs = new Filesystem();
    $symfonyFs->mkdir($tempRoot);

    $oldHome = getenv('HOME') ?: null;
    $oldUserProfile = getenv('USERPROFILE') ?: null;
    $oldHomeDrive = getenv('HOMEDRIVE') ?: null;
    $oldHomePath = getenv('HOMEPATH') ?: null;
    $oldXdgCacheHome = getenv('XDG_CACHE_HOME') ?: null;
    $oldLocalAppData = getenv('LOCALAPPDATA') ?: null;
    $oldAppData = getenv('APPDATA') ?: null;

    $customHome = $tempRoot . '/home';
    $customXdg = $tempRoot . '/xdg-cache';
    $customLocalAppData = $tempRoot . '/local-app-data';
    $symfonyFs->mkdir([$customHome, $customXdg, $customLocalAppData]);

    putenv("HOME={$customHome}");
    putenv('USERPROFILE');
    putenv('HOMEDRIVE');
    putenv('HOMEPATH');
    putenv("XDG_CACHE_HOME={$customXdg}");
    putenv("LOCALAPPDATA={$customLocalAppData}");
    putenv('APPDATA');

    try {
        $callback($customHome, $customXdg, $customLocalAppData);
    } finally {
        $oldHome !== null ? putenv("HOME={$oldHome}") : putenv('HOME');
        $oldUserProfile !== null ? putenv("USERPROFILE={$oldUserProfile}") : putenv('USERPROFILE');
        $oldHomeDrive !== null ? putenv("HOMEDRIVE={$oldHomeDrive}") : putenv('HOMEDRIVE');
        $oldHomePath !== null ? putenv("HOMEPATH={$oldHomePath}") : putenv('HOMEPATH');
        $oldXdgCacheHome !== null ? putenv("XDG_CACHE_HOME={$oldXdgCacheHome}") : putenv('XDG_CACHE_HOME');
        $oldLocalAppData !== null ? putenv("LOCALAPPDATA={$oldLocalAppData}") : putenv('LOCALAPPDATA');
        $oldAppData !== null ? putenv("APPDATA={$oldAppData}") : putenv('APPDATA');
        $symfonyFs->remove($tempRoot);
    }
}
