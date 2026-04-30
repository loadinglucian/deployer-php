# GitHub Actions Rules

Rules for GitHub Actions workflows, reusable actions, and CI/CD configuration in DeployerPHP.

> **IMPORTANT**
>
> - Use `setup-php-composer` action for all PHP workflows
> - Quality gate workflows must complete in under 3 minutes
> - Integration test workflows use `fail-fast: false` for comprehensive coverage

## Context

### Workflow Categories

| Category          | Trigger        | Timeout | Purpose                      |
| ----------------- | -------------- | ------- | ---------------------------- |
| Quality Gates     | `pull_request` | 3min    | Fast feedback on PR quality  |
| Integration Tests | `pull_request` | 9min    | Real-world testing           |
| Automation        | Various        | N/A     | Dependabot automation        |

**Quality Gate Workflows:**

- `pest.yml` - Unit tests with coverage
- `phpstan.yml` - Static analysis
- `pint.yml` - Code style
- `rector.yml` - Automated refactoring checks
- `ci-canary.yml` - Verify linters catch known violations

**Integration Test Workflows:**

- `bats-vm.yml` - Server command tests (Lima VMs, 3-distro matrix)
- `bats-cloud.yml` - Cloud provider tests (AWS/DO matrix)

**Automation Workflows:**

- `dependabot-automerge.yml` - Auto-approve and merge dependabot PRs

### Directory Structure

```text
.github/
├── workflows/
│   ├── pest.yml              # Unit tests
│   ├── phpstan.yml           # Static analysis
│   ├── pint.yml              # Code style
│   ├── rector.yml            # Automated refactoring
│   ├── ci-canary.yml         # Linter verification
│   ├── bats-vm.yml           # VM integration tests
│   ├── bats-cloud.yml        # Cloud integration tests
│   └── dependabot-automerge.yml
├── actions/
│   └── setup-php-composer/
│       └── action.yml        # Reusable PHP setup
└── dependabot.yml            # Dependency update config
```

### Reusable Action: setup-php-composer

All PHP workflows use the local composite action for consistent setup:

```yaml
- name: Setup PHP and Composer
  uses: ./.github/actions/setup-php-composer
  with:
      php-version: '8.2' # Optional, default: 8.2
      coverage: 'true' # Optional, default: false (enables xdebug)
```

**Inputs:**

| Input         | Default | Description                |
| ------------- | ------- | -------------------------- |
| `php-version` | `8.2`   | PHP version to install     |
| `coverage`    | `false` | Enable xdebug for coverage |

### Secrets vs Variables

**Secrets (sensitive, encrypted):**

- `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_REGION`
- `AWS_TEST_INSTANCE_TYPE`, `AWS_TEST_DISK_SIZE`
- `DO_API_TOKEN`
- `CF_API_TOKEN`
- `SSH_PRIVATE_KEY_B64` (base64-encoded SSH key)
- `DOTENV_FILE` (full .env file contents)

**Variables (public infrastructure IDs):**

- `AWS_TEST_IMAGE`, `AWS_TEST_KEY_PAIR`, `AWS_TEST_VPC`, `AWS_TEST_SUBNET`
- `AWS_TEST_HOSTED_ZONE`, `AWS_TEST_DOMAIN`
- `DO_TEST_SSH_KEY_ID`, `DO_TEST_REGION`, `DO_TEST_SIZE`, `DO_TEST_IMAGE`, `DO_TEST_VPC_UUID`
- `DO_TEST_DOMAIN`, `CF_TEST_DOMAIN`

## Examples

### Example: Quality Gate Workflow

```yaml
name: PHPStan

permissions:
    contents: read

on:
    pull_request:

jobs:
    phpstan:
        runs-on: ubuntu-latest
        timeout-minutes: 3
        concurrency:
            group: ${{ github.workflow }}-${{ github.ref }}
            cancel-in-progress: true

        steps:
            - name: Checkout code
              uses: actions/checkout@v4

            - name: Setup PHP and Composer
              uses: ./.github/actions/setup-php-composer

            - name: Run static analysis
              run: vendor/bin/phpstan analyse --error-format=github
```

### Example: Integration Test with Matrix

```yaml
name: BATS VM Tests

permissions:
    contents: read

on:
    pull_request:

jobs:
    vm-tests:
        runs-on: ubuntu-24.04
        timeout-minutes: 9

        strategy:
            fail-fast: false
            matrix:
                distro: [ubuntu24]

        concurrency:
            group: bats-vm-${{ matrix.distro }}-${{ github.ref }}
            cancel-in-progress: true

        steps:
            - uses: actions/checkout@v4
            - uses: ./.github/actions/setup-php-composer

            - name: Run VM tests for ${{ matrix.distro }}
              env:
                  CI: 'true'
                  BATS_DISTRO: ${{ matrix.distro }}
              run: ./bats.sh ci vm ${{ matrix.distro }}
```

### Example: Cloud Test Job-Level Env Vars

Cloud test credentials and config are defined at **job level** (not step level), so all steps share them:

```yaml
jobs:
    cloud-tests:
        runs-on: ubuntu-24.04
        timeout-minutes: 9
        env:
            CI: 'true'
            BATS_RUN_SUFFIX: ${{ github.run_id }}
            AWS_ACCESS_KEY_ID: ${{ secrets.AWS_ACCESS_KEY_ID }}
            AWS_TEST_IMAGE: ${{ vars.AWS_TEST_IMAGE }}
            DO_API_TOKEN: ${{ secrets.DO_API_TOKEN }}
            # ... all provider vars
        steps:
            - run: ./bats.sh ci cloud ${{ matrix.provider }}
```

### Run-Scoped Resource Isolation

Cloud CI jobs must set `BATS_RUN_SUFFIX: ${{ github.run_id }}` at job scope.

Resource naming rules (servers, keys, DNS roots, secondary `.v2` domains) are
defined in one place: `# BATS Rules` → `Run-Scoped Resource Isolation (Cloud)`
and implemented in `tests/bats/lib/cloud-helpers.bash`.

### Backstop Cleanup

Cloud tests use a two-tier `if: always()` cleanup step after the test run:

1. **Tier 1 — Deployer CLI:** Attempts cleanup through normal deployer commands
2. **Tier 2 — Raw cloud CLI:** Falls back to `aws` CLI / DO API `curl` if Deployer itself is broken

This is intentionally duplicated inline (not shared from BATS helpers) so cleanup works even if the helper code is the source of failure.

### Example: Adding a New Quality Gate

```yaml
name: New Check

permissions:
    contents: read

on:
    pull_request:

jobs:
    check:
        runs-on: ubuntu-latest
        timeout-minutes: 3
        concurrency:
            group: ${{ github.workflow }}-${{ github.ref }}
            cancel-in-progress: true

        steps:
            - uses: actions/checkout@v4
            - uses: ./.github/actions/setup-php-composer
            - run: vendor/bin/some-tool --check
```

### Example: Adding Integration Test Matrix Entry

To add a new distro to VM tests:

```yaml
strategy:
    fail-fast: false
    matrix:
        distro: [ubuntu24, new-distro] # Add here
```

To add a new cloud provider:

```yaml
strategy:
    fail-fast: false
    matrix:
        provider: [aws, do, new-provider] # Add here
```

## Rules

### Workflow Patterns

- Quality gates: 3-minute timeout, PR trigger, `ubuntu-latest`
- Integration tests: 9 minute timeout, PR trigger, `ubuntu-24.04`
- Cloud tests: skip fork PRs with `if: ${{ github.event.pull_request.head.repo.fork == false }}` (forks cannot access secrets)
- Always use `concurrency` to cancel superseded runs
- Matrix jobs use `fail-fast: false` for comprehensive coverage

### Concurrency Groups

```yaml
# Quality gates: group by workflow and ref
group: ${{ github.workflow }}-${{ github.ref }}

# Matrix jobs: include matrix key to allow parallel matrix entries
group: bats-vm-${{ matrix.distro }}-${{ github.ref }}
group: bats-cloud-${{ matrix.provider }}-${{ github.ref }}
```

### Permissions

- Use minimal permissions for each workflow
- Quality gates: `contents: read`
- Dependabot automerge: `pull-requests: write`, `contents: write`

### Triggers

- Quality gates: `pull_request`
- Integration tests: `pull_request`

### Environment Files

Cloud tests load credentials from `.env` file:

```yaml
- name: Setup environment file
  run: |
      cat <<'EOF' > .env
      ${{ secrets.DOTENV_FILE }}
      EOF
```

## Constraints

- Never commit workflow changes directly to main
- Use `ci/*` branches to test workflow changes
- All PR checks must pass before merge
- Never store secrets in variables (use encrypted secrets)
- Quality gate timeouts must not exceed 3 minutes
- Always use setup-php-composer for PHP workflows (ensures caching)
