#!/usr/bin/env bats

# DigitalOcean Integration Tests
# Tests: do:key:add, do:key:list, do:key:delete, do:provision
#
# Prerequisites:
#   - DIGITALOCEAN_API_TOKEN or DO_API_TOKEN in environment
#   - Valid DigitalOcean API token with droplet permissions
#   - SSH private key at ~/.ssh/id_ed25519

load 'lib/helpers'
load 'lib/cloud-helpers'

# ----
# Setup/Teardown
# ----

setup_file() {
	require_do_credentials
	do_cleanup_all
}

teardown_file() {
	if ! do_credentials_available; then
		return 0
	fi
	do_cleanup_all
	rm -f "$TEST_INVENTORY"
}

setup() {
	cloud_check_failed
	require_do_credentials
}

teardown() {
	cloud_mark_failed
}

# ----
# do:key:add
# ----

@test "do:key:add uploads public key to DigitalOcean" {
	run_deployer do:key:add \
		--name="$DO_TEST_KEY_NAME" \
		--public-key-path="$CLOUD_TEST_KEY_PATH"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "Public SSH key uploaded successfully"
	assert_output_contains "ID:"
	assert_command_replay "do:key:add"
}

# ----
# do:key:list
# ----

@test "do:key:list shows uploaded key" {
	run_deployer do:key:list

	debug_output

	[ "$status" -eq 0 ]
	assert_output_contains "$DO_TEST_KEY_NAME"
	assert_command_replay "do:key:list"
}

# ----
# do:key:delete
# ----

@test "do:key:delete removes key from DigitalOcean" {
	# Find key ID by name (safe: only deletes key we created)
	local key_id
	key_id=$(do_find_key_id_by_name "$DO_TEST_KEY_NAME")

	# Safety: only proceed if we found a key with our test name
	[[ -n "$key_id" ]] || skip "Test key not found"

	run_deployer do:key:delete \
		--key="$key_id" \
		--force \
		--yes

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "Public SSH key deleted successfully"
	assert_command_replay "do:key:delete"
}

@test "do:key:list confirms key deleted" {
	run_deployer do:key:list

	debug_output

	[ "$status" -eq 0 ]
	assert_output_not_contains "$DO_TEST_KEY_NAME"
}

# ----
# do:provision
# ----

@test "do:provision creates droplet and adds to inventory" {
	require_do_provision_config

	# Cleanup any leftover test server
	do_cleanup_test_server

	run_deployer do:provision \
		--name="$DO_TEST_SERVER_NAME" \
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
	assert_command_replay "do:provision"
}

@test "server:install configures DigitalOcean provisioned server" {
	require_do_provision_config

	# Full install takes time - use longer timeout
	run timeout 600 "$DEPLOYER_BIN" --inventory="$TEST_INVENTORY" --no-ansi server:install \
		--server="$DO_TEST_SERVER_NAME" \
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
# do:dns:set
# ----

@test "do:dns:set creates A record for root domain" {
	require_do_provision_config

	# Get server IP from inventory
	local server_ip
	server_ip=$(get_server_ip "$DO_TEST_SERVER_NAME")

	[[ -n "$server_ip" ]] || skip "Could not determine server IP"

	run_deployer do:dns:set \
		--zone="$DO_TEST_DOMAIN" \
		--type="A" \
		--name="$DO_TEST_DNS_ROOT" \
		--value="$server_ip" \
		--ttl="60"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "DNS record"
	assert_output_contains "successfully"
	assert_command_replay "do:dns:set"
}

@test "do:dns:set creates A record for www subdomain" {
	require_do_provision_config

	# Get server IP from inventory
	local server_ip
	server_ip=$(get_server_ip "$DO_TEST_SERVER_NAME")

	[[ -n "$server_ip" ]] || skip "Could not determine server IP"

	run_deployer do:dns:set \
		--zone="$DO_TEST_DOMAIN" \
		--type="A" \
		--name="$DO_TEST_DNS_WWW" \
		--value="$server_ip" \
		--ttl="60"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "DNS record"
	assert_output_contains "successfully"
	assert_command_replay "do:dns:set"
}

# ----
# site:create
# ----

@test "site:create creates site on DigitalOcean provisioned server" {
	require_do_provision_config

	# Cleanup any leftover test site
	cleanup_test_site "$DO_TEST_DOMAIN"

	run_deployer site:create \
		--domain="$DO_TEST_DOMAIN" \
		--server="$DO_TEST_SERVER_NAME" \
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
# site:dns:check
# ----

@test "site:dns:check resolves DNS for DigitalOcean site" {
	require_do_provision_config

	run_deployer site:dns:check \
		--domain="$DO_TEST_DOMAIN"

	debug_output

	[ "$status" -eq 0 ]
	assert_output_contains "Check DNS"
	assert_output_contains "Domain: $DO_TEST_DOMAIN"
	assert_output_contains "A:"
	assert_output_contains "AAAA:"
	assert_command_replay "site:dns:check"
}

# ----
# site:shared:push
# ----

@test "site:shared:push uploads .env to DigitalOcean site" {
	require_do_provision_config

	run_deployer site:shared:push \
		--domain="$DO_TEST_DOMAIN" \
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

@test "site:deploy deploys application to DigitalOcean site" {
	require_do_provision_config

	# Deploy takes time - use longer timeout
	run timeout 300 "$DEPLOYER_BIN" --inventory="$TEST_INVENTORY" --no-ansi site:deploy \
		--domain="$DO_TEST_DOMAIN" \
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

@test "deployed DigitalOcean site responds to HTTP requests" {
	require_do_provision_config

	# Get server IP to bypass DNS (faster than waiting for propagation)
	local server_ip
	server_ip=$(get_server_ip "$DO_TEST_SERVER_NAME")

	# Wait for HTTP response containing our test message (30 seconds - should be immediate with direct IP)
	wait_for_http "$DO_TEST_DOMAIN" "$CLOUD_TEST_APP_MESSAGE" 30 "$server_ip"
}

# ----
# Cleanup
# ----

@test "server:delete removes DigitalOcean droplet" {
	require_do_provision_config

	run_deployer server:delete \
		--server="$DO_TEST_SERVER_NAME" \
		--force \
		--yes \
		--destroy-cloud

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "Droplet destroyed"
	assert_output_contains "removed from inventory"
	assert_command_replay "server:delete"
}
