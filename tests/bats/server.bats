#!/usr/bin/env bats

# Server command tests
# Tests: server:add, server:info, server:delete, server:install

load 'lib/helpers'
load 'lib/lima'
load 'lib/inventory'

# ----
# Setup/Teardown
# ----

setup() {
    reset_inventory
}

# ----
# server:add
# ----

@test "server:add creates server in inventory with valid options" {
    run_deployer server:add \
        --name="$TEST_SERVER_NAME" \
        --host="$TEST_SERVER_HOST" \
        --port="$TEST_SERVER_PORT" \
        --username="$TEST_SERVER_USER" \
        --private-key-path="$TEST_KEY"

    debug_output

    [ "$status" -eq 0 ]
    assert_success_output
    assert_output_contains "added to inventory"
    assert_command_replay "server:add"
    inventory_has_server "$TEST_SERVER_NAME"
}

@test "server:add accepts IP address as host" {
    run_deployer server:add \
        --name="ip-server" \
        --host="$TEST_SERVER_HOST" \
        --port="$TEST_SERVER_PORT" \
        --username="$TEST_SERVER_USER" \
        --private-key-path="$TEST_KEY"

    debug_output

    [ "$status" -eq 0 ]
    assert_success_output
    inventory_has_server "ip-server"
}

@test "server:add accepts hostname as host" {
    run_deployer server:add \
        --name="hostname-server" \
        --host="localhost" \
        --port="$TEST_SERVER_PORT" \
        --username="$TEST_SERVER_USER" \
        --private-key-path="$TEST_KEY"

    debug_output

    [ "$status" -eq 0 ]
    assert_success_output
    inventory_has_server "hostname-server"
}

# ----
# server:info
# ----

@test "server:info displays server information" {
    add_test_server

    run_deployer server:info --server="$TEST_SERVER_NAME"

    debug_output

    [ "$status" -eq 0 ]
    assert_output_contains "Distro"
    assert_command_replay "server:info"
}

@test "server:info shows info when no servers in inventory" {
    reset_inventory

    run_deployer server:info --server="nonexistent"

    debug_output

    assert_info_output
    assert_output_contains "No servers found"
}

@test "server:info shows correct server details" {
    add_test_server

    run_deployer server:info --server="$TEST_SERVER_NAME"

    debug_output

    [ "$status" -eq 0 ]
    assert_output_contains "$TEST_SERVER_HOST"
}

# ----
# server:delete
# ----

@test "server:delete removes server from inventory" {
    add_test_server
    inventory_has_server "$TEST_SERVER_NAME"

    run_deployer server:delete \
        --server="$TEST_SERVER_NAME" \
        --force \
        --yes \
        --inventory-only

    debug_output

    [ "$status" -eq 0 ]
    assert_success_output
    assert_output_contains "removed"
    ! inventory_has_server "$TEST_SERVER_NAME"
}

@test "server:delete shows info when no servers in inventory" {
    reset_inventory

    run_deployer server:delete \
        --server="nonexistent" \
        --force \
        --yes \
        --inventory-only

    debug_output

    assert_info_output
    assert_output_contains "No servers found"
}

@test "server:delete with --inventory-only removes from inventory" {
    add_test_server "inventory-only-server"
    inventory_has_server "inventory-only-server"

    run_deployer server:delete \
        --server="inventory-only-server" \
        --force \
        --yes \
        --inventory-only

    debug_output

    [ "$status" -eq 0 ]
    assert_success_output
    ! inventory_has_server "inventory-only-server"
}

# NOTE: "server:delete fails when typed name doesn't match" cannot be tested
# in BATS because Laravel Prompts doesn't support piped stdin in non-TTY mode.
# The type-to-confirm logic is tested manually or requires expect/pty tooling.

# ----
# server:install
# ----

@test "server:install shows info when no servers in inventory" {
    reset_inventory

    run_deployer server:install --server="nonexistent"

    debug_output

    assert_info_output
    assert_output_contains "No servers found"
}

@test "server:install completes successfully with generated deploy key" {
    add_test_server

    # Full install takes time - use longer timeout
    run timeout 300 "$DEPLOYER_BIN" --inventory="$TEST_INVENTORY" server:install \
        --server="$TEST_SERVER_NAME" \
        --generate-deploy-key \
        --php-version="8.4" \
        --php-extensions="cli,fpm,curl,mbstring"

    debug_output

    [ "$status" -eq 0 ]
    assert_success_output
    assert_output_contains "Server installation completed"
    assert_output_contains "public key"
    assert_command_replay "server:install"
}

@test "server:install creates deployer user on remote" {
    # Relies on previous install test or assumes server is already installed
    add_test_server

    # Check deployer user exists - command failure fails the test
    ssh_exec "id deployer"
}

@test "server:install creates deployer home directory" {
    add_test_server

    assert_remote_dir_exists "/home/deployer"
}

@test "server:install creates deployer sites directory" {
    add_test_server

    assert_remote_dir_exists "/home/deployer/sites"
}

@test "server:install installs Caddy web server" {
    add_test_server

    # Command failure fails the test
    ssh_exec "command -v caddy"
}

@test "server:install creates Caddy config structure" {
    add_test_server

    assert_remote_dir_exists "/etc/caddy/conf.d/sites"
}

@test "server:install installs PHP-FPM" {
    add_test_server

    # Check for any PHP-FPM version - command failure fails the test
    ssh_exec "ls /etc/php/*/fpm/php-fpm.conf 2>/dev/null | head -1"
}

@test "server:install creates deploy key" {
    add_test_server

    assert_remote_file_exists "/home/deployer/.ssh/id_ed25519"
    assert_remote_file_exists "/home/deployer/.ssh/id_ed25519.pub"
}

@test "server:install with custom deploy key uses provided key" {
    add_test_server

    # Get the public key content from our test key
    local expected_key
    expected_key=$(cat "${TEST_KEY}.pub")

    # Run install with custom key
    run timeout 300 "$DEPLOYER_BIN" --inventory="$TEST_INVENTORY" server:install \
        --server="$TEST_SERVER_NAME" \
        --custom-deploy-key="$TEST_KEY" \
        --php-version="8.4" \
        --php-extensions="cli,fpm"

    debug_output

    [ "$status" -eq 0 ]
    assert_success_output

    # Verify the remote key matches our test key
    local remote_key
    remote_key=$(ssh_exec "cat /home/deployer/.ssh/id_ed25519.pub")
    [[ "$remote_key" == "$expected_key" ]]
}
