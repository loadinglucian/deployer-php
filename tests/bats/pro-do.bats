#!/usr/bin/env bats

# DigitalOcean Integration Tests
# Tests: pro:do:key:add, pro:do:key:list, pro:do:key:delete, pro:do:provision
#
# Prerequisites:
#   - DIGITALOCEAN_API_TOKEN or DO_API_TOKEN in environment
#   - Valid DigitalOcean API token with droplet permissions
#   - SSH private key at ~/.ssh/id_ed25519

load 'lib/helpers'
load 'lib/pro-helpers'

# ----
# Setup/Teardown
# ----

setup_file() {
	# Skip all tests if DO credentials not configured
	if ! do_credentials_available; then
		skip "DigitalOcean credentials not configured"
	fi

	# Clean up any leftover test key from previous runs
	do_cleanup_test_key
}

setup() {
	# Skip individual test if credentials unavailable
	if ! do_credentials_available; then
		skip "DigitalOcean credentials not configured"
	fi
}

# ----
# pro:do:key:add
# ----

@test "pro:do:key:add uploads public key to DigitalOcean" {
	run_deployer pro:do:key:add \
		--name="$PRO_TEST_KEY_NAME" \
		--public-key-path="$PRO_TEST_KEY_PATH"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "Public SSH key uploaded successfully"
	assert_output_contains "ID:"
	assert_command_replay "pro:do:key:add"
}

# ----
# pro:do:key:list
# ----

@test "pro:do:key:list shows uploaded key" {
	run_deployer pro:do:key:list

	debug_output

	[ "$status" -eq 0 ]
	assert_output_contains "$PRO_TEST_KEY_NAME"
	assert_command_replay "pro:do:key:list"
}

# ----
# pro:do:key:delete
# ----

@test "pro:do:key:delete removes key from DigitalOcean" {
	# Find key ID by name (safe: only deletes key we created)
	local key_id
	key_id=$(do_find_key_id_by_name "$PRO_TEST_KEY_NAME")

	# Safety: only proceed if we found a key with our test name
	[[ -n "$key_id" ]] || skip "Test key not found"

	run_deployer pro:do:key:delete \
		--key="$key_id" \
		--force \
		--yes

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "Public SSH key deleted successfully"
	assert_command_replay "pro:do:key:delete"
}

@test "pro:do:key:list confirms key deleted" {
	run_deployer pro:do:key:list

	debug_output

	[ "$status" -eq 0 ]
	assert_output_not_contains "$PRO_TEST_KEY_NAME"
}

# ----
# pro:do:provision
# ----

@test "pro:do:provision creates droplet and adds to inventory" {
	# Skip if DO credentials or SSH key not available
	if ! do_provision_config_available; then
		skip "DO credentials not configured or SSH key missing"
	fi

	# Cleanup any leftover test server
	do_cleanup_test_server

	run_deployer pro:do:provision \
		--name="$PRO_TEST_SERVER_NAME" \
		--region="$DO_TEST_REGION" \
		--size="$DO_TEST_SIZE" \
		--image="$DO_TEST_IMAGE" \
		--ssh-key-id="$DO_TEST_SSH_KEY_ID" \
		--private-key-path="$DO_TEST_PRIVATE_KEY_PATH" \
		--no-backups \
		--monitoring \
		--ipv6 \
		--vpc-uuid="$DO_TEST_VPC_UUID"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "Droplet provisioned"
	assert_output_contains "Droplet is active"
	assert_output_contains "Server added to inventory"
	assert_command_replay "pro:do:provision"
}

@test "server:delete removes DigitalOcean droplet" {
	# Skip if DO credentials or SSH key not available
	if ! do_provision_config_available; then
		skip "DO credentials not configured or SSH key missing"
	fi

	run_deployer server:delete \
		--server="$PRO_TEST_SERVER_NAME" \
		--force \
		--yes

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "Droplet destroyed"
	assert_output_contains "removed from inventory"
	assert_command_replay "server:delete"
}
