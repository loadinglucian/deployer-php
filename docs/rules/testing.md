# Testing Rules

All rules MANDATORY.

**Philosophy:** "A test that never fails is not a test, it's a lie."

**Framework:** Pest exclusively, `it()` syntax, 70%+ coverage

## Running Tests

```bash
composer pest                 # Full suite with coverage (parallel)
vendor/bin/pest $TEST_FILE    # Specific file
```

## Dependency Injection in Tests

DI Container rule applies to PRODUCTION code, not tests.

**Unit Tests - Manual Instantiation:**

```php
$mockFs = mockFilesystem(true, 'content');
$service = new EnvService(new FilesystemService($mockFs), new Dotenv());
```

**Command Tests - Container with Bindings:**

```php
$container = mockCommandContainer();
$command = $container->build(ServerAddCommand::class);

// Override services
$container = mockCommandContainer(ssh: $mockSSH);

// Pre-populate data
$container = mockCommandContainer(
    inventoryData: ['servers' => [['name' => 'web1', 'host' => '192.168.1.1']]]
);
```

**Maintenance:** When adding service to BaseCommand, update `mockCommandContainer()` in `tests/TestHelpers.php`.

## Test Minimalism

**Target:** Keep test files under 1.8x source code size.

**Rules:**
- Test core business logic only
- Use datasets: `->with([])` for multiple scenarios
- Eliminate overlap: no two tests covering same functionality
- Consolidate assertions: `expect($x)->toBe(1)->and($y)->toBe(2)`
- Mock external dependencies only

**Don't consolidate:** Different public methods, exception vs normal flow, distinct business logic.

## AAA Pattern

```php
it('does something', function () {
    // ARRANGE
    $service = new Service(mock(Dependency::class));

    // ACT
    $result = $service->action();

    // ASSERT
    expect($result)->toBe('expected');
});
```

Exception tests: `// ACT & ASSERT` when act triggers assertion.

## Testing Patterns

**Forbidden:**

```php
expect($x)->toBeInstanceOf(Class::class);   // Type-only
expect($x)->toBeArray();                    // Generic
expect($x)->not->toBeNull();                // Meaningless alone
expect(true)->toBeTrue();                   // Literally meaningless
sleep(...);                                 // Test logic not time
```

**Required:**

```php
expect($config->getValue('host'))->toBe('example.com');
$mock->shouldReceive('method')->with('param')->andReturn('result');

// For polling/timeout - use zero intervals
$service->waitForReady('id', timeout: 10, pollInterval: 0);
```

## Test Types

**Unit Tests:**
- Mock all external dependencies
- Test single units in isolation
- Complete in milliseconds

**Integration Tests:**
- Real file operations and external processes
- CLI commands and full workflows

**Layer Strategy:**
- CLI Commands → Integration tests
- Business Services → Unit tests
- Utilities/Helpers → Unit tests

## Static Analysis

PHPStan applies to PRODUCTION code, not tests. Focus on functionality over type compliance.
