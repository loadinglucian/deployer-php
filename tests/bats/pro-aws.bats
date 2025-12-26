#!/usr/bin/env bats

# AWS Integration Tests
# Tests: pro:aws:key:add, pro:aws:key:list, pro:aws:key:delete, pro:aws:provision
#
# Prerequisites:
#   - AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_DEFAULT_REGION in environment
#   - Valid AWS credentials with EC2 permissions
#   - SSH private key at ~/.ssh/id_ed25519

load 'lib/helpers'
load 'lib/pro-helpers'

# ----
# Setup/Teardown
# ----

setup_file() {
	# Skip all tests if AWS credentials not configured
	if ! aws_credentials_available; then
		skip "AWS credentials not configured"
	fi

	# Clean up any leftover test key from previous runs
	aws_cleanup_test_key
}

setup() {
	# Skip individual test if credentials unavailable
	if ! aws_credentials_available; then
		skip "AWS credentials not configured"
	fi
}

# ----
# pro:aws:key:add
# ----

@test "pro:aws:key:add uploads public key to AWS" {
	run_deployer pro:aws:key:add \
		--name="$PRO_TEST_KEY_NAME" \
		--public-key-path="$PRO_TEST_KEY_PATH"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "Key pair imported successfully"
	assert_output_contains "Name: $PRO_TEST_KEY_NAME"
	assert_command_replay "pro:aws:key:add"
}

# ----
# pro:aws:key:list
# ----

@test "pro:aws:key:list shows uploaded key" {
	run_deployer pro:aws:key:list

	debug_output

	[ "$status" -eq 0 ]
	assert_output_contains "$PRO_TEST_KEY_NAME"
	assert_command_replay "pro:aws:key:list"
}

# ----
# pro:aws:key:delete
# ----

@test "pro:aws:key:delete removes key from AWS" {
	run_deployer pro:aws:key:delete \
		--key="$PRO_TEST_KEY_NAME" \
		--force \
		--yes

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "Key pair deleted successfully"
	assert_command_replay "pro:aws:key:delete"
}

@test "pro:aws:key:list confirms key deleted" {
	run_deployer pro:aws:key:list

	debug_output

	[ "$status" -eq 0 ]
	assert_output_not_contains "$PRO_TEST_KEY_NAME"
}

# ----
# pro:aws:provision
# ----

@test "pro:aws:provision creates EC2 instance and adds to inventory" {
	# Skip if AWS credentials or SSH key not available
	if ! aws_provision_config_available; then
		skip "AWS credentials not configured or SSH key missing"
	fi

	# Cleanup any leftover test server
	aws_cleanup_test_server

	run_deployer pro:aws:provision \
		--name="$PRO_TEST_SERVER_NAME" \
		--instance-type="$AWS_TEST_INSTANCE_TYPE" \
		--ami="$AWS_TEST_AMI" \
		--key-pair="$AWS_TEST_KEY_PAIR" \
		--private-key-path="$AWS_TEST_PRIVATE_KEY_PATH" \
		--vpc="$AWS_TEST_VPC" \
		--subnet="$AWS_TEST_SUBNET" \
		--disk-size="$AWS_TEST_DISK_SIZE" \
		--no-monitoring

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "Instance provisioned"
	assert_output_contains "Instance is running"
	assert_output_contains "Elastic IP allocated"
	assert_output_contains "Server added to inventory"
	assert_command_replay "pro:aws:provision"
}

@test "server:delete removes AWS instance and cleans up resources" {
	# Skip if AWS credentials or SSH key not available
	if ! aws_provision_config_available; then
		skip "AWS credentials not configured or SSH key missing"
	fi

	run_deployer server:delete \
		--server="$PRO_TEST_SERVER_NAME" \
		--force \
		--yes

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "Instance terminated"
	assert_output_contains "Elastic IP released"
	assert_output_contains "removed from inventory"
	assert_command_replay "server:delete"
}
