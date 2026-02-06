#!/usr/bin/env bats

# AWS Integration Tests
# Tests: aws:key:add, aws:key:list, aws:key:delete, aws:provision
#
# Prerequisites:
#   - AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_REGION in environment
#   - Valid AWS credentials with EC2 permissions
#   - SSH private key at ~/.ssh/id_ed25519

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

@test "aws:key:add uploads public key to AWS" {
	run_deployer aws:key:add \
		--name="$AWS_TEST_KEY_NAME" \
		--public-key-path="$CLOUD_TEST_KEY_PATH"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "Key pair imported successfully"
	assert_output_contains "Name: $AWS_TEST_KEY_NAME"
	assert_command_replay "aws:key:add"
}

# ----
# aws:key:list
# ----

@test "aws:key:list shows uploaded key" {
	run_deployer aws:key:list

	debug_output

	[ "$status" -eq 0 ]
	assert_output_contains "$AWS_TEST_KEY_NAME"
	assert_command_replay "aws:key:list"
}

# ----
# aws:key:delete
# ----

@test "aws:key:delete removes key from AWS" {
	run_deployer aws:key:delete \
		--key="$AWS_TEST_KEY_NAME" \
		--force \
		--yes

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "Key pair deleted successfully"
	assert_command_replay "aws:key:delete"
}

@test "aws:key:list confirms key deleted" {
	run_deployer aws:key:list

	debug_output

	[ "$status" -eq 0 ]
	assert_output_not_contains "$AWS_TEST_KEY_NAME"
}

# ----
# aws:provision
# ----

@test "aws:provision creates EC2 instance and adds to inventory" {
	require_aws_provision_config

	# Cleanup any leftover test server
	aws_cleanup_test_server

	run_deployer aws:provision \
		--name="$AWS_TEST_SERVER_NAME" \
		--instance-type="$AWS_TEST_INSTANCE_TYPE" \
		--image="$AWS_TEST_IMAGE" \
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
	assert_command_replay "aws:provision"
}

@test "server:install configures AWS provisioned server" {
	require_aws_provision_config

	# Full install takes time - use longer timeout
	run timeout 600 "$DEPLOYER_BIN" --inventory="$TEST_INVENTORY" --no-ansi server:install \
		--server="$AWS_TEST_SERVER_NAME" \
		--generate-deploy-key \
		--timezone="UTC" \
		--php-version="$CLOUD_TEST_PHP_VERSION" \
		--php-extensions="$CLOUD_TEST_PHP_EXTENSIONS"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "Server installation completed"
	assert_output_contains "public key"
	assert_command_replay "server:install"
}

# ----
# aws:dns:set
# ----

@test "aws:dns:set creates A record for root domain" {
	require_aws_provision_config

	# Get server IP from inventory
	local server_ip
	server_ip=$(get_server_ip "$AWS_TEST_SERVER_NAME")

	[[ -n "$server_ip" ]] || skip "Could not determine server IP"

	run_deployer aws:dns:set \
		--zone="$AWS_TEST_HOSTED_ZONE" \
		--type="A" \
		--name="$AWS_TEST_DNS_ROOT" \
		--value="$server_ip" \
		--ttl="60"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "DNS record upserted successfully"
	assert_command_replay "aws:dns:set"
}

@test "aws:dns:set creates A record for www subdomain" {
	require_aws_provision_config

	# Get server IP from inventory
	local server_ip
	server_ip=$(get_server_ip "$AWS_TEST_SERVER_NAME")

	[[ -n "$server_ip" ]] || skip "Could not determine server IP"

	run_deployer aws:dns:set \
		--zone="$AWS_TEST_HOSTED_ZONE" \
		--type="A" \
		--name="$AWS_TEST_DNS_WWW" \
		--value="$server_ip" \
		--ttl="60"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "DNS record upserted successfully"
	assert_command_replay "aws:dns:set"
}

# ----
# cf:dns:set
# ----

@test "cf:dns:set creates A record for root domain (non-proxied)" {
	require_aws_provision_config

	# Get server IP from inventory
	local server_ip
	server_ip=$(get_server_ip "$AWS_TEST_SERVER_NAME")

	[[ -n "$server_ip" ]] || skip "Could not determine server IP"

	run_deployer cf:dns:set \
		--zone="$CF_TEST_DOMAIN" \
		--type="A" \
		--name="$CF_TEST_DNS_ROOT" \
		--value="$server_ip" \
		--ttl="60" \
		--no-proxied

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "DNS record"
	assert_output_contains "successfully"
	assert_command_replay "cf:dns:set"
}

@test "cf:dns:set creates A record for www subdomain (non-proxied)" {
	require_aws_provision_config

	# Get server IP from inventory
	local server_ip
	server_ip=$(get_server_ip "$AWS_TEST_SERVER_NAME")

	[[ -n "$server_ip" ]] || skip "Could not determine server IP"

	run_deployer cf:dns:set \
		--zone="$CF_TEST_DOMAIN" \
		--type="A" \
		--name="$CF_TEST_DNS_WWW" \
		--value="$server_ip" \
		--ttl="60" \
		--no-proxied

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "DNS record"
	assert_output_contains "successfully"
	assert_command_replay "cf:dns:set"
}

# ----
# cf:dns:list
# ----

@test "cf:dns:list shows created records" {
	require_aws_provision_config

	run_deployer cf:dns:list \
		--zone="$CF_TEST_DOMAIN"

	debug_output

	[ "$status" -eq 0 ]
	assert_output_contains "$CF_TEST_DOMAIN"
	assert_command_replay "cf:dns:list"
}

# ----
# cf:dns:delete
# ----

@test "cf:dns:delete removes www A record" {
	require_aws_provision_config

	run_deployer cf:dns:delete \
		--zone="$CF_TEST_DOMAIN" \
		--type="A" \
		--name="$CF_TEST_DNS_WWW" \
		--force \
		--yes

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "DNS record deleted successfully"
	assert_command_replay "cf:dns:delete"
}

@test "cf:dns:delete removes root A record" {
	require_aws_provision_config

	run_deployer cf:dns:delete \
		--zone="$CF_TEST_DOMAIN" \
		--type="A" \
		--name="$CF_TEST_DNS_ROOT" \
		--force \
		--yes

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "DNS record deleted successfully"
	assert_command_replay "cf:dns:delete"
}

# ----
# site:create
# ----

@test "site:create creates site on AWS provisioned server" {
	require_aws_provision_config

	# Cleanup any leftover test site
	cleanup_test_site "$AWS_TEST_DOMAIN"

	run_deployer site:create \
		--domain="$AWS_TEST_DOMAIN" \
		--server="$AWS_TEST_SERVER_NAME" \
		--php-version="$CLOUD_TEST_PHP_VERSION" \
		--www-mode="redirect-to-root" \
		--web-root="/"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "added to inventory"
	assert_command_replay "site:create"
}

# ----
# site:shared:push
# ----

@test "site:shared:push uploads .env to AWS site" {
	require_aws_provision_config

	run_deployer site:shared:push \
		--domain="$AWS_TEST_DOMAIN" \
		--local="${BATS_TEST_ROOT}/fixtures/env/deploy-me.env" \
		--remote=".env"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "Shared file uploaded"
	assert_command_replay "site:shared:push"
}

# ----
# site:deploy
# ----

@test "site:deploy deploys application to AWS site" {
	require_aws_provision_config

	# Deploy takes time - use longer timeout
	run timeout 300 "$DEPLOYER_BIN" --inventory="$TEST_INVENTORY" --no-ansi site:deploy \
		--domain="$AWS_TEST_DOMAIN" \
		--repo="$CLOUD_TEST_DEPLOY_REPO" \
		--branch="$CLOUD_TEST_DEPLOY_BRANCH" \
		--force \
		--yes

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "Deployment completed"
	assert_command_replay "site:deploy"
}

# ----
# HTTP Verification
# ----

@test "deployed AWS site responds to HTTP requests" {
	require_aws_provision_config

	# Get server IP to bypass DNS (faster than waiting for propagation)
	local server_ip
	server_ip=$(get_server_ip "$AWS_TEST_SERVER_NAME")

	# Wait for HTTP response containing our test message (30 seconds - should be immediate with direct IP)
	wait_for_http "$AWS_TEST_DOMAIN" "$CLOUD_TEST_APP_MESSAGE" 30 "$server_ip"
}

# ----
# Cleanup
# ----

@test "server:delete removes AWS instance and cleans up resources" {
	require_aws_provision_config

	run_deployer server:delete \
		--server="$AWS_TEST_SERVER_NAME" \
		--force \
		--yes \
		--destroy-cloud

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "Instance terminated"
	assert_output_contains "Elastic IP released"
	assert_output_contains "removed from inventory"
	assert_command_replay "server:delete"
}
