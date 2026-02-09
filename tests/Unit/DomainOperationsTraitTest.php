<?php

declare(strict_types=1);

use DeployerPHP\Container;
use DeployerPHP\Exceptions\ValidationException;
use DeployerPHP\Services\HttpService;
use DeployerPHP\Traits\DomainOperationsTrait;
use Symfony\Component\Filesystem\Filesystem;

interface DomainOperationsSubject
{
    public function check(string $domain): bool;
}

it('resolves registrable boundaries using public suffix list rules', function () {
    withIsolatedHomeEnvironment(function () {
        $container = new Container();
        bindHttpServiceWithPslFixture($container);
        $subject = makeDomainOperationsSubject($container);

        expect($subject->check('example.uk'))->toBeFalse();
        expect($subject->check('example.co.uk'))->toBeFalse();
        expect($subject->check('blog.example.uk'))->toBeTrue();
        expect($subject->check('blog.example.co.uk'))->toBeTrue();
        expect($subject->check('example.ne.jp'))->toBeFalse();
        expect($subject->check('blog.example.com'))->toBeTrue();
    });
});

it('surfaces classifier failures as validation exceptions', function () {
    withIsolatedHomeEnvironment(function () {
        $container = new Container();
        bindHttpServiceFailure($container);
        $subject = makeDomainOperationsSubject($container);

        expect(fn () => $subject->check('blog.example.com'))
            ->toThrow(ValidationException::class);
    });
});

function withIsolatedHomeEnvironment(callable $callback): void
{
    $tempHome = sys_get_temp_dir() . '/deployer-php-domain-test-' . bin2hex(random_bytes(6));
    $fs = new Filesystem();
    $fs->mkdir($tempHome);

    $oldHome = getenv('HOME') ?: null;
    $oldUserProfile = getenv('USERPROFILE') ?: null;
    $oldHomeDrive = getenv('HOMEDRIVE') ?: null;
    $oldHomePath = getenv('HOMEPATH') ?: null;
    $oldXdgCacheHome = getenv('XDG_CACHE_HOME') ?: null;

    putenv("HOME={$tempHome}");
    putenv('USERPROFILE');
    putenv('HOMEDRIVE');
    putenv('HOMEPATH');
    putenv('XDG_CACHE_HOME');

    try {
        $callback();
    } finally {
        $oldHome !== null ? putenv("HOME={$oldHome}") : putenv('HOME');
        $oldUserProfile !== null ? putenv("USERPROFILE={$oldUserProfile}") : putenv('USERPROFILE');
        $oldHomeDrive !== null ? putenv("HOMEDRIVE={$oldHomeDrive}") : putenv('HOMEDRIVE');
        $oldHomePath !== null ? putenv("HOMEPATH={$oldHomePath}") : putenv('HOMEPATH');
        $oldXdgCacheHome !== null ? putenv("XDG_CACHE_HOME={$oldXdgCacheHome}") : putenv('XDG_CACHE_HOME');
        $fs->remove($tempHome);
    }
}

function bindHttpServiceWithPslFixture(Container $container): void
{
    $container->bind(HttpService::class, new class () extends HttpService {
        public function __construct()
        {
        }

        public function verifyUrl(string $url): array
        {
            return [
                'success' => true,
                'status_code' => 200,
                'body' => implode("\n", [
                    '// ===BEGIN ICANN DOMAINS===',
                    'com',
                    'jp',
                    'ne.jp',
                    'uk',
                    'co.uk',
                    'net.uk',
                    '*.sch.uk',
                ]),
            ];
        }
    });
}

function bindHttpServiceFailure(Container $container): void
{
    $container->bind(HttpService::class, new class () extends HttpService {
        public function __construct()
        {
        }

        public function verifyUrl(string $url): array
        {
            return [
                'success' => false,
                'status_code' => 503,
                'body' => 'offline',
            ];
        }
    });
}

function makeDomainOperationsSubject(Container $container): DomainOperationsSubject
{
    return new class ($container) implements DomainOperationsSubject {
        use DomainOperationsTrait;

        public function __construct(
            protected readonly Container $container,
        ) {
        }

        public function check(string $domain): bool
        {
            return $this->isSubdomain($domain);
        }
    };
}
