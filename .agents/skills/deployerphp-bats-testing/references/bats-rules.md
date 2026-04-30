# BATS Rules

Functional CLI testing using BATS (Bash Automated Testing System). Tests are divided into two categories: VM tests (using Lima VMs via SSH) and Cloud tests (against real cloud provider APIs).

> **IMPORTANT**
>
> - SUCCESS paths only - no failure-path tests (those belong in PHP unit tests)
> - BATS must only test NON-INTERACTIVE command paths (Laravel Prompts requires TTY)
> - Always provide ALL CLI options to skip prompts

## Context

### Testing Philosophy

BATS tests are a "test drive around the track" - proving the system works end-to-end with valid inputs.

**Success paths only:**

- Test commands complete successfully with valid inputs
- Verify side effects (files created, services running, users exist)

**Rationale:**

- Long-running commands (e.g., `server:install`) take 5+ minutes per distro
- Timeout-based tests are inherently flaky
- Integration tests prove the system works; unit tests prove edge cases fail correctly

### Structure

```text
bats.sh                      # Test runner (interactive menu + CI mode)
tests/bats/
├── vm.bats                  # VM/server command tests (Lima required)
├── cloud-aws.bats           # AWS provisioning tests (no VM)
├── cloud-do.bats            # DigitalOcean provisioning tests (no VM)
├── lima/
│   └── ubuntu24.yaml        # Ubuntu 24.04 VM config
├── lib/
│   ├── helpers.bash         # Assertions and test utilities
│   ├── cloud-helpers.bash   # Cloud provider credential checks and cleanup
│   ├── lima-core.bash       # Shared Lima functions (used by bats.sh and lima.bash)
│   ├── lima.bash            # Lima VM lifecycle management (BATS context)
│   └── inventory.bash       # Test inventory manipulation
└── fixtures/
    ├── keys/                # SSH keys for test servers
    └── inventory/           # Test inventory files
```

### Test Categories

| Category | Tests                             | Requirements             | Trigger               |
| -------- | --------------------------------- | ------------------------ | --------------------- |
| VM       | `vm.bats`                         | Lima VMs, SSH access     | `./bats.sh run vm`    |
| Cloud    | `cloud-aws.bats`, `cloud-do.bats` | Provider API credentials | `./bats.sh run cloud` |

**VM Tests** — server management and service installations:

- Server lifecycle: add, delete, info, firewall, logs, run, ssh
- Service installs: mariadb, postgresql, redis, memcached
- Runs against Lima VMs via SSH
- Require Lima installed locally

**Cloud Tests** — cloud provisioning and site lifecycle:

- Cloud provider integration: provision, DNS, key management
- Site lifecycle: create, shared:{push,list,pull}, deploy, dns:check, https
- HTTP verification of deployed sites
- Runs against real cloud APIs (AWS, DigitalOcean, Cloudflare)
- Fail with diagnostics if credentials not configured (no silent skips)

### Per-Suite Inventory Isolation

Each BATS test file gets its own inventory file (e.g., `cloud-aws.yml`, `vm-ubuntu24.yml`), created automatically by `helpers.bash`. This prevents test suites from interfering with each other and enables parallel execution. VM tests append the distro via `BATS_INVENTORY_SUFFIX` (set by `bats.sh`). Both VM and cloud `teardown_file()` clean up their inventory with `rm -f "$TEST_INVENTORY"`.

### VM Testing

VM tests run against Ubuntu. Each distro has its own VM and SSH port:

| Distro   | Port | VM Instance            |
| -------- | ---- | ---------------------- |
| ubuntu24 | 2224 | deployer-test-ubuntu24 |

### Run-Scoped Resource Isolation (Cloud)

Cloud tests use `BATS_RUN_SUFFIX` to make all resource names unique per run, enabling safe parallel CI execution.

**Source:** Computed once in `bats.sh` before invoking BATS. CI: last 6 chars of `BATS_RUN_SUFFIX` env var (set from `github.run_id`). Local: `bats.sh` PID (`$$`). `cloud-helpers.bash` inherits the value; it does not recompute it.

**Run-scoped variables (cloud-helpers.bash):**

| Variable               | Pattern                      | Example                    |
| ---------------------- | ---------------------------- | -------------------------- |
| `AWS_TEST_SERVER_NAME` | `deployer-bats-aws-{SUFFIX}` | `deployer-bats-aws-123456` |
| `AWS_TEST_KEY_NAME`    | `deployer-bats-aws-{SUFFIX}` | `deployer-bats-aws-123456` |
| `AWS_TEST_DNS_ROOT`    | `r{SUFFIX}`                  | `r123456`                  |
| `AWS_TEST_SITE_DOMAIN` | `r{SUFFIX}.{zone}`           | `r123456.deployeraws.eu`   |
| `DO_TEST_SERVER_NAME`  | `deployer-bats-do-{SUFFIX}`  | `deployer-bats-do-123456`  |
| `DO_TEST_KEY_NAME`     | `deployer-bats-do-{SUFFIX}`  | `deployer-bats-do-123456`  |
| `DO_TEST_DNS_ROOT`     | `r{SUFFIX}`                  | `r123456`                  |
| `DO_TEST_SITE_DOMAIN`  | `r{SUFFIX}.{zone}`           | `r123456.deployerdo.eu`    |
| `CF_TEST_DNS_ROOT`     | `r{SUFFIX}`                  | `r123456`                  |

### Entry Points

**`bats.sh`** is the test runner script at the project root. It handles VM lifecycle (Lima), interactive menus, CI mode, and resource isolation. All BATS test execution flows through this script.

**Composer scripts** (`composer.json`) provide convenient aliases:

| Composer Script       | Equivalent        | Description                  |
| --------------------- | ----------------- | ---------------------------- |
| `composer bats`       | `./bats.sh run`   | Run tests (interactive menu) |
| `composer bats:start` | `./bats.sh start` | Start Lima VMs               |
| `composer bats:stop`  | `./bats.sh stop`  | Stop Lima VMs                |

### Commands

```bash
# Interactive mode (shows category menu)
./bats.sh                    # Select: cloud or vm -> select target

# Direct category (prompts for target)
./bats.sh run cloud          # Prompts for provider: all, aws, do
./bats.sh run vm             # Prompts for distro: all, ubuntu24

# Direct test file
./bats.sh run cloud-aws      # Run AWS cloud tests directly
./bats.sh run cloud-do       # Run DigitalOcean cloud tests directly

# CI mode (non-interactive, requires CI=true)
CI=true ./bats.sh ci cloud aws      # Run AWS cloud tests
CI=true ./bats.sh ci cloud do       # Run DO cloud tests
CI=true ./bats.sh ci cloud all      # Run all cloud tests
CI=true ./bats.sh ci vm ubuntu24    # Run VM tests on ubuntu24

# VM management
./bats.sh start [distro]     # Start VMs (all if no distro)
./bats.sh stop [distro]      # Stop VMs
./bats.sh reset [distro]     # Factory reset VMs (delete + recreate)
./bats.sh clean [distro]     # Clean VM state without restart
./bats.sh ssh <distro>       # SSH into a test VM

# Debug mode
BATS_DEBUG=1 ./bats.sh run   # Enable verbose debug output
```

### Running Tests

**Always use `bats.sh` as the entry point.** It manages VM lifecycle, sets env vars, and ensures isolation.

**Full suite (recommended for CI and final validation):**

```bash
# Start VM, run all VM tests on a distro, stop VM
CI=true ./bats.sh ci vm ubuntu24

# Interactive: prompts for category and target
./bats.sh run vm
```

**Single test filtering (for quick iteration during development):**

`bats.sh` does not support `--filter`. To run a single test, start the VM separately, then invoke `bats` directly with the required env vars:

```bash
# 1. Start the VM (only needed once)
./bats.sh start ubuntu24

# 2. Run a single test by name (BATS_DISTRO and BATS_INVENTORY_SUFFIX are required)
BATS_DISTRO=ubuntu24 BATS_INVENTORY_SUFFIX=ubuntu24 \
  bats --print-output-on-failure --filter "mysql:install saves credentials" tests/bats/vm.bats

# 3. Stop the VM when done
./bats.sh stop ubuntu24
```

| Env Var                 | Purpose                                  | Required       |
| ----------------------- | ---------------------------------------- | -------------- |
| `BATS_DISTRO`           | Selects SSH port from `DISTRO_PORTS` map | Yes (VM tests) |
| `BATS_INVENTORY_SUFFIX` | Isolates inventory file per distro       | Yes (VM tests) |
| `BATS_DEBUG=1`          | Enables `debug_output()` verbose logging | No             |

**Cloud tests (no VM needed):**

```bash
./bats.sh run cloud-aws       # Run AWS cloud tests
./bats.sh run cloud-do        # Run DO cloud tests

# Filter a single cloud test
bats --print-output-on-failure --filter "aws:key:add" tests/bats/cloud-aws.bats
```

### Available Helpers

**Assertions (helpers.bash):**

- `assert_success_output` - Output contains `✓`
- `assert_error_output` - Output contains `✗`
- `assert_info_output` - Output contains `ℹ`
- `assert_warning_output` - Output contains `!`
- `assert_output_contains "text"` - Output contains string
- `assert_output_not_contains "text"` - Output does NOT contain string
- `assert_command_replay "cmd"` - Output contains command replay
- `assert_success` - Exit status is 0
- `assert_failure` - Exit status is non-zero

**Execution (helpers.bash):**

- `run_deployer cmd --opt` - Run deployer with test inventory and `--no-ansi`
- `run_deployer_success cmd` - Run and assert success
- `run_deployer_failure cmd` - Run and assert failure
- `debug_output` - Print output when BATS_DEBUG=1
- `debug "message"` - Print debug message when BATS_DEBUG=1

**Inventory (inventory.bash):**

- `reset_inventory` - Empty inventory
- `add_test_server [name]` - Add test server to inventory
- `inventory_has_server "name"` - Check server exists
- `inventory_has_site "domain"` - Check site exists

**SSH (helpers.bash):**

- `ssh_exec "command"` - Execute on test VM
- `assert_remote_file_exists "/path"` - Check remote file
- `assert_remote_dir_exists "/path"` - Check remote directory
- `assert_remote_file_contains "/path" "text"` - Check file content

**Lima (lima.bash):**

- `lima_clean` - Clean VM state via SSH
- `lima_is_running` - Check if VM is running
- `lima_logs [lines]` - Get VM system logs

**Fail-Fast (cloud-helpers.bash):**

- `cloud_mark_failed` - Write sentinel file when test fails (call from `teardown()`)
- `cloud_check_failed` - Skip if a previous test failed (call from `setup()`)

**Cloud Credentials (cloud-helpers.bash):**

- `aws_credentials_available` - Check AWS env vars set
- `do_credentials_available` - Check DO API token set
- `cf_credentials_available` - Check Cloudflare API token set
- `aws_provision_config_available` - Check full AWS config for provisioning
- `do_provision_config_available` - Check full DO config for provisioning
- `require_aws_credentials` - Fail with diagnostics if AWS credentials missing
- `require_aws_provision_config` - Fail with diagnostics if AWS provisioning config incomplete
- `require_cf_credentials` - Fail with diagnostics if Cloudflare credentials missing
- `require_do_credentials` - Fail with diagnostics if DO credentials missing
- `require_do_provision_config` - Fail with diagnostics if DO provisioning config incomplete

**Cloud Cleanup (cloud-helpers.bash):**

- `aws_cleanup_test_key` - Remove AWS test SSH key
- `aws_cleanup_test_server` - Remove AWS test server and cloud instance
- `aws_cleanup_test_dns` - Remove Route53 run-scoped A records (CLI)
- `aws_cleanup_test_dns_raw` - Remove Route53 run-scoped A records (raw AWS CLI safety net)
- `aws_cleanup_all` - Full AWS cleanup (server → DNS → DNS raw → site → key)
- `do_cleanup_test_key` - Remove DO test SSH key
- `do_cleanup_test_server` - Remove DO test server and droplet
- `do_cleanup_test_dns` - Remove DO DNS run-scoped A records (CLI)
- `do_cleanup_test_dns_raw` - Remove DO DNS run-scoped A records (raw DO API safety net)
- `do_cleanup_all` - Full DO cleanup (server → DNS → DNS raw → site → key)
- `cf_cleanup_test_dns` - Remove Cloudflare run-scoped A records (CLI)
- `cf_cleanup_test_dns_raw` - Remove Cloudflare run-scoped A records (raw CF API safety net)
- `cf_cleanup_all` - Full CF cleanup (DNS → DNS raw)
- `do_find_key_id_by_name "name"` - Find DO key ID by name
- `do_extract_key_id_from_output "output"` - Extract key ID from command output

**Shared Cloud Helpers (cloud-helpers.bash):**

- `get_server_ip "server-name"` - Get server IP from inventory
- `cleanup_test_site "domain"` - Remove test site from inventory
- `wait_for_http "domain" ["content"] [timeout] ["ip"]` - Wait for HTTP response

### Adding a New Distro

1. Create `lima/{distro}.yaml` (copy existing, update image URLs and port)
2. Add to `DISTRO_PORTS` and `DISTROS` in `bats.sh`
3. Add to `DISTRO_PORTS` in `lib/helpers.bash`

### Dependencies

```bash
# VM tests require Lima
brew install bats-core lima jq

# Cloud tests only need BATS
brew install bats-core jq
```

## Examples

### Example: VM Test Template

```bash
#!/usr/bin/env bats

load 'lib/helpers'
load 'lib/lima'
load 'lib/inventory'

# ----
# Setup/Teardown
# ----

teardown_file() {
    rm -f "$TEST_INVENTORY"
}

setup() {
    reset_inventory
}

# ----
# command:name
# ----

@test "command:name does something" {
    add_test_server

    run_deployer command:name --option=value

    debug_output

    [ "$status" -eq 0 ]
    assert_success_output
    assert_output_contains "Expected text"
    assert_command_replay "command:name"
}
```

### Example: Cloud Test Template

```bash
#!/usr/bin/env bats

load 'lib/helpers'
load 'lib/cloud-helpers'

# ----
# Setup/Teardown
# ----

setup_file() {
    require_aws_credentials
    require_cf_credentials
    aws_cleanup_all
}

teardown_file() {
    # Use raw *_available() - cleanup must always attempt
    if ! aws_credentials_available; then
        return 0
    fi
    aws_cleanup_all
    rm -f "$TEST_INVENTORY"
}

setup() {
    cloud_check_failed
    require_aws_credentials
}

teardown() {
    cloud_mark_failed
}

# ----
# aws:key:add
# ----

@test "aws:key:add uploads public key" {
    run_deployer aws:key:add \
        --name="$AWS_TEST_KEY_NAME" \
        --public-key-path="$CLOUD_TEST_KEY_PATH"

    debug_output

    [ "$status" -eq 0 ]
    assert_success_output
    assert_output_contains "Key pair imported successfully"
    assert_command_replay "aws:key:add"
}
```

### Example: Credential Guard Pattern

```bash
@test "aws:provision creates EC2 instance" {
    require_aws_provision_config

    run_deployer aws:provision \
        --name="$AWS_TEST_SERVER_NAME" \
        --instance-type="$AWS_TEST_INSTANCE_TYPE" \
        # ... more options

    debug_output

    [ "$status" -eq 0 ]
    assert_success_output
}
```

### Example: Full Lifecycle Test

```bash
@test "aws:provision full lifecycle: provision -> install -> deploy -> verify -> cleanup" {
    require_aws_provision_config

    # Provision server
    run_deployer aws:provision --name="$AWS_TEST_SERVER_NAME" ...
    [ "$status" -eq 0 ]

    # Get server IP for later verification
    local server_ip
    server_ip=$(get_server_ip "$AWS_TEST_SERVER_NAME")

    # Install server (PHP, Nginx, etc.)
    run_deployer server:install --server="$AWS_TEST_SERVER_NAME" ...
    [ "$status" -eq 0 ]

    # Create and deploy site
    run_deployer site:create --server="$AWS_TEST_SERVER_NAME" --domain="$AWS_TEST_DOMAIN" ...
    [ "$status" -eq 0 ]

    run_deployer site:deploy --domain="$AWS_TEST_DOMAIN" ...
    [ "$status" -eq 0 ]

    # Verify deployment (bypass DNS using direct IP)
    wait_for_http "$AWS_TEST_DOMAIN" "$CLOUD_TEST_APP_MESSAGE" 180 "$server_ip"

    # Cleanup
    cleanup_test_site "$AWS_TEST_DOMAIN"
    aws_cleanup_test_server
}
```

### Example: Test Side Effect

```bash
@test "command creates expected files on remote" {
    add_test_server
    assert_remote_file_exists "/home/deployer/.ssh/id_ed25519"
    assert_remote_dir_exists "/home/deployer/sites"
}
```

## Rules

- Use `--yes` / `-y` to skip confirmations
- Use `--force` / `-f` to skip type-to-confirm prompts
- Input validation and failure-path tests belong in PHP unit tests
- Each distro has isolated VM instance and SSH port
- Interactive-only behavior cannot be tested in BATS
- Cloud tests **fail** with diagnostics when provider credentials are missing — no silent skips
- Use `require_*` guards in `setup_file()`, `setup()`, and per-test; use raw `*_available()` only in `teardown_file()` (cleanup must always attempt)
- Use `setup_file()` for preventive cleanup and `teardown_file()` for reactive cleanup, `setup()` for per-test credential checks
