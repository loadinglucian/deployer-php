---
name: testing
description: Use this skill when writing, creating, or modifying Pest tests. Activates for tasks involving unit tests, integration tests, test assertions, mocking, or test coverage improvements.
---

# Pest Testing Development

Tests verify behavior using Pest PHP with the `it()` syntax. Tests follow AAA pattern and prioritize testing business logic over type checking.

All rules MANDATORY.

**Philosophy:** "A test that never fails is not a test, it's a lie."

**Coverage:** Maintain 70%+ code coverage.

## Running Tests

```bash
composer pest                 # Full suite with coverage (parallel)
vendor/bin/pest $TEST_FILE    # Specific file
```

## Required Structure

Every test file MUST follow this structure:

```php
<?php

declare(strict_types=1);

//
// {Feature} tests
// ----

it('does something specific', function () {
    // ARRANGE
    $service = new Service(mock(Dependency::class));

    // ACT
    $result = $service->action();

    // ASSERT
    expect($result)->toBe('expected');
});

it('throws when invalid input', function () {
    $service = new Service();

    // ACT & ASSERT
    expect(fn () => $service->action('invalid'))
        ->toThrow(InvalidArgumentException::class, 'Expected message');
});
```

## AAA Pattern

Every test MUST follow Arrange-Act-Assert:

```php
it('calculates total with tax', function () {
    // ARRANGE
    $calculator = new PriceCalculator(taxRate: 0.1);
    $items = [['price' => 100], ['price' => 50]];

    // ACT
    $total = $calculator->calculateTotal($items);

    // ASSERT
    expect($total)->toBe(165.0);
});
```

**Exception tests:** Use `// ACT & ASSERT` when the act triggers the assertion:

```php
it('throws on negative price', function () {
    $calculator = new PriceCalculator();

    // ACT & ASSERT
    expect(fn () => $calculator->calculateTotal([['price' => -10]]))
        ->toThrow(InvalidArgumentException::class);
});
```

## Test Naming

Use descriptive `it()` statements that read as sentences:

```php
// CORRECT
it('returns empty array when no servers configured')
it('throws when SSH connection fails')
it('creates deploy key with correct permissions')

// WRONG
it('test1')
it('works')
it('should return correct value')
```

## Dependency Injection in Tests

DI Container rules apply to PRODUCTION code, not tests.

**Unit Tests - Manual Instantiation:**

```php
it('parses config correctly', function () {
    $mockFs = mock(FilesystemInterface::class);
    $mockFs->shouldReceive('read')->with('/path')->andReturn('content');

    $service = new ConfigService($mockFs);
    $result = $service->parse('/path');

    expect($result)->toBe(['key' => 'value']);
});
```

**Command Tests - Container with Bindings:**

```php
it('adds server successfully', function () {
    $mockSSH = mock(SSHService::class);
    $mockSSH->shouldReceive('connect')->andReturn(true);

    $container = mockCommandContainer(ssh: $mockSSH);
    $command = $container->build(ServerAddCommand::class);

    // Test command execution
});
```

## Test Minimalism

**Target:** Keep test files under 1.8x source code size.

**Rules:**

1. Test core business logic only
2. Use datasets for multiple scenarios: `->with([])`
3. Eliminate overlap: no two tests covering same functionality
4. Consolidate assertions: `expect($x)->toBe(1)->and($y)->toBe(2)`
5. Mock external dependencies only

**Don't consolidate:** Different public methods, exception vs normal flow, distinct business logic.

## Datasets for Multiple Scenarios

```php
it('validates server names', function (string $name, bool $valid) {
    $validator = new ServerValidator();

    expect($validator->isValidName($name))->toBe($valid);
})->with([
    'valid simple' => ['web-server', true],
    'valid with numbers' => ['web1', true],
    'invalid spaces' => ['web server', false],
    'invalid special chars' => ['web@server', false],
    'empty string' => ['', false],
]);
```

## Assertion Chaining

```php
// CORRECT - Chained assertions
expect($server)
    ->name->toBe('web1')
    ->and($server)
    ->host->toBe('192.168.1.1')
    ->and($server)
    ->port->toBe(22);

// WRONG - Separate expect calls for related assertions
expect($server->name)->toBe('web1');
expect($server->host)->toBe('192.168.1.1');
expect($server->port)->toBe(22);
```

## Forbidden Patterns

Never write these assertions:

```php
// Type-only checks (prove nothing about behavior)
expect($x)->toBeInstanceOf(Class::class);
expect($x)->toBeArray();
expect($x)->not->toBeNull();

// Literally meaningless
expect(true)->toBeTrue();

// Time-dependent (test logic, not time)
sleep(1);
usleep(1000);
```

## Required Patterns

Always verify actual values and mock interactions:

```php
// CORRECT - Verify actual value
expect($config->getValue('host'))->toBe('example.com');

// CORRECT - Verify mock interaction
$mock->shouldReceive('method')->with('param')->andReturn('result');

// For polling/timeout operations - use zero intervals
$service->waitForReady('id', timeout: 10, pollInterval: 0);
```

## Mocking with Mockery

```php
use Mockery;

it('calls external service', function () {
    $mock = mock(ExternalService::class);
    $mock->shouldReceive('fetch')
        ->once()
        ->with('param')
        ->andReturn(['data']);

    $service = new MyService($mock);
    $result = $service->process();

    expect($result)->toBe('processed');
});
```

**Mock patterns:**

```php
// Return value
$mock->shouldReceive('method')->andReturn('value');

// Multiple calls with different returns
$mock->shouldReceive('method')
    ->andReturn('first', 'second', 'third');

// Throw exception
$mock->shouldReceive('method')
    ->andThrow(new RuntimeException('error'));

// Verify call count
$mock->shouldReceive('method')->once();
$mock->shouldReceive('method')->twice();
$mock->shouldReceive('method')->times(3);
$mock->shouldReceive('method')->never();

// Argument matching
$mock->shouldReceive('method')->with('exact');
$mock->shouldReceive('method')->with(Mockery::any());
$mock->shouldReceive('method')->with(Mockery::type('string'));
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

| Layer | Test Type |
|-------|-----------|
| CLI Commands | Integration tests |
| Business Services | Unit tests |
| Utilities/Helpers | Unit tests |

## Architecture Tests

Use Pest's arch testing for structural rules:

```php
arch('commands extend BaseCommand', function () {
    expect('Deployer\\Console\\')
        ->classes()
        ->toHaveSuffix('Command')
        ->toExtend(BaseCommand::class);
});

arch('services are final', function () {
    expect('Deployer\\Service\\')
        ->classes()
        ->toBeFinal();
});
```

## Static Analysis

PHPStan applies to PRODUCTION code, not tests. Focus on functionality over type compliance.

## Test Organization

### Section Comments

```php
//
// Server validation tests
// ----

it('validates server name format', ...);
it('validates server host', ...);

//
// Server creation tests
// ----

it('creates server with defaults', ...);
```

### File Naming

- Test files: `tests/Unit/ServiceNameTest.php` or `tests/Integration/FeatureTest.php`
- Mirror source structure where practical

## Checklist

Before completing tests:

- [ ] Every test follows AAA pattern with comments
- [ ] Test names are descriptive sentences
- [ ] No forbidden assertion patterns
- [ ] Datasets used for multiple similar scenarios
- [ ] Assertions verify actual values, not just types
- [ ] Mocks verify interactions where relevant
- [ ] No test overlap (each behavior tested once)
- [ ] Tests complete in milliseconds (unit) or seconds (integration)
