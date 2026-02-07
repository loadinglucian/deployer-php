#!/usr/bin/env bats

# AWS Integration Tests
# Tests: AWS key/provision/DNS + site lifecycle commands
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
	require_aws_dns_prefix_config
	require_cf_dns_prefix_config
	cloud_note "Run suffix: ${BATS_RUN_SUFFIX}"
	aws_cleanup_all
	cf_cleanup_all
}

teardown_file() {
	if ! aws_credentials_available; then
		return 0
	fi
	aws_cleanup_all
	cf_cleanup_all
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

	# Tag contract for janitor discovery
	aws_assert_instance_tag_contract "$AWS_TEST_SERVER_NAME"
	aws_assert_eip_tag_contract "$AWS_TEST_SERVER_NAME"
	aws_assert_root_volume_tag_contract "$AWS_TEST_SERVER_NAME"
}

@test "server:install configures AWS provisioned server" {
	require_aws_provision_config

	local primary_php_version secondary_php_version installed_php_versions
	primary_php_version="$CLOUD_TEST_PHP_PRIMARY_VERSION"
	secondary_php_version="$CLOUD_TEST_PHP_SECONDARY_VERSION"

	# Full install takes time - use longer timeout
	run timeout 600 "$DEPLOYER_BIN" --inventory="$TEST_INVENTORY" --no-ansi server:install \
		--server="$AWS_TEST_SERVER_NAME" \
		--generate-deploy-key \
		--timezone="UTC" \
		--php-version="$primary_php_version" \
		--php-extensions="$CLOUD_TEST_PHP_EXTENSIONS"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "Server installation completed"
	assert_output_contains "public key"
	assert_command_replay "server:install"

	# Install secondary PHP-FPM version on the same server (keep primary as default)
	run timeout 600 "$DEPLOYER_BIN" --inventory="$TEST_INVENTORY" --no-ansi server:install \
		--server="$AWS_TEST_SERVER_NAME" \
		--generate-deploy-key \
		--timezone="UTC" \
		--php-version="$secondary_php_version" \
		--no-php-default \
		--php-extensions="$CLOUD_TEST_PHP_EXTENSIONS"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "Server installation completed"
	assert_command_replay "server:install"

	installed_php_versions="$(get_installed_php_fpm_versions_for_server "$AWS_TEST_SERVER_NAME")"
	printf '%s\n' "$installed_php_versions" | grep -qx "$primary_php_version"
	printf '%s\n' "$installed_php_versions" | grep -qx "$secondary_php_version"
}

# ----
# site:create
# ----

@test "site:create creates site ${AWS_TEST_SITE_DOMAIN} on AWS provisioned server" {
	require_aws_provision_config

	# Cleanup any leftover test site
	cleanup_test_site "$AWS_TEST_SITE_DOMAIN"

	run_deployer site:create \
		--domain="$AWS_TEST_SITE_DOMAIN" \
		--server="$AWS_TEST_SERVER_NAME" \
		--php-version="$CLOUD_TEST_PHP_PRIMARY_VERSION" \
		--web-root="/"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "added to inventory"
	assert_command_replay "site:create"
}

@test "site:create creates secondary site ${AWS_TEST_SITE_DOMAIN_SECONDARY} on AWS provisioned server" {
	require_aws_provision_config

	# Cleanup any leftover secondary test site
	cleanup_test_site "$AWS_TEST_SITE_DOMAIN_SECONDARY"

	run_deployer site:create \
		--domain="$AWS_TEST_SITE_DOMAIN_SECONDARY" \
		--server="$AWS_TEST_SERVER_NAME" \
		--php-version="$CLOUD_TEST_PHP_SECONDARY_VERSION" \
		--web-root="/"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "added to inventory"
	assert_command_replay "site:create"
}

# ----
# aws:dns:set
# ----

@test "aws:dns:set creates prefixed A record for ${AWS_TEST_DNS_ROOT_FQDN}" {
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

@test "aws:dns:set creates secondary prefixed A record for ${AWS_TEST_DNS_ROOT_SECONDARY_FQDN}" {
	require_aws_provision_config

	local server_ip
	server_ip=$(get_server_ip "$AWS_TEST_SERVER_NAME")

	[[ -n "$server_ip" ]] || skip "Could not determine server IP"

	run_deployer aws:dns:set \
		--zone="$AWS_TEST_HOSTED_ZONE" \
		--type="A" \
		--name="$AWS_TEST_DNS_ROOT_SECONDARY" \
		--value="$server_ip" \
		--ttl="60"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "DNS record upserted successfully"
	assert_command_replay "aws:dns:set"
}

@test "aws:dns:list shows ${AWS_TEST_DNS_ROOT_FQDN} and ${AWS_TEST_DNS_ROOT_SECONDARY_FQDN}" {
	require_aws_provision_config

	run_deployer aws:dns:list \
		--zone="$AWS_TEST_HOSTED_ZONE"

	debug_output

	[ "$status" -eq 0 ]
	assert_output_contains "$AWS_TEST_HOSTED_ZONE"
	assert_output_contains "$AWS_TEST_DNS_ROOT_FQDN"
	assert_output_contains "$AWS_TEST_DNS_ROOT_SECONDARY_FQDN"
	assert_command_replay "aws:dns:list"
}

# ----
# cf:dns:set
# ----

@test "cf:dns:set creates prefixed A record for ${CF_TEST_DNS_ROOT_FQDN} (non-proxied)" {
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

@test "cf:dns:list shows ${CF_TEST_DNS_ROOT_FQDN}" {
	require_aws_provision_config

	run_deployer cf:dns:list \
		--zone="$CF_TEST_DOMAIN"

	debug_output

	[ "$status" -eq 0 ]
	assert_output_contains "$CF_TEST_DOMAIN"
	assert_command_replay "cf:dns:list"
}

# ----
# site:dns:check
# ----

@test "site:dns:check resolves DNS for ${AWS_TEST_SITE_DOMAIN}" {
	require_aws_provision_config

	local server_ip
	server_ip=$(get_server_ip "$AWS_TEST_SERVER_NAME")

	[[ -n "$server_ip" ]] || skip "Could not determine server IP"
	wait_for_dns_a_record "$AWS_TEST_SITE_DOMAIN" "$server_ip" 300

	run_deployer site:dns:check \
		--domain="$AWS_TEST_SITE_DOMAIN"

	debug_output

	[ "$status" -eq 0 ]
	assert_output_contains "Check DNS"
	assert_output_contains "Domain: $AWS_TEST_SITE_DOMAIN"
	assert_output_contains "A:"
	assert_output_contains "AAAA:"
	assert_output_contains "$server_ip"
	assert_command_replay "site:dns:check"
}

@test "site:dns:check resolves DNS for ${AWS_TEST_SITE_DOMAIN_SECONDARY}" {
	require_aws_provision_config

	local server_ip
	server_ip=$(get_server_ip "$AWS_TEST_SERVER_NAME")

	[[ -n "$server_ip" ]] || skip "Could not determine server IP"
	wait_for_dns_a_record "$AWS_TEST_SITE_DOMAIN_SECONDARY" "$server_ip" 300

	run_deployer site:dns:check \
		--domain="$AWS_TEST_SITE_DOMAIN_SECONDARY"

	debug_output

	[ "$status" -eq 0 ]
	assert_output_contains "Check DNS"
	assert_output_contains "Domain: $AWS_TEST_SITE_DOMAIN_SECONDARY"
	assert_output_contains "A:"
	assert_output_contains "AAAA:"
	assert_output_contains "$server_ip"
	assert_command_replay "site:dns:check"
}

# ----
# site:shared:push
# ----

@test "site:shared:push uploads .env to AWS site" {
	require_aws_provision_config

	run_deployer site:shared:push \
		--domain="$AWS_TEST_SITE_DOMAIN" \
		--local="${BATS_TEST_ROOT}/fixtures/env/deploy-me.env" \
		--remote=".env"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "Shared file uploaded"
	assert_command_replay "site:shared:push"
}

@test "site:shared:push uploads .env to AWS secondary site" {
	require_aws_provision_config

	run_deployer site:shared:push \
		--domain="$AWS_TEST_SITE_DOMAIN_SECONDARY" \
		--local="${BATS_TEST_ROOT}/fixtures/env/deploy-me.env" \
		--remote=".env"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "Shared file uploaded"
	assert_command_replay "site:shared:push"
}

# ----
# site:shared:list
# ----

@test "site:shared:list shows uploaded shared files for AWS site" {
	require_aws_provision_config

	run_deployer site:shared:list \
		--domain="$AWS_TEST_SITE_DOMAIN"

	debug_output

	[ "$status" -eq 0 ]
	assert_output_contains ".env"
	assert_command_replay "site:shared:list"
}

# ----
# site:shared:pull
# ----

@test "site:shared:pull downloads .env from AWS site" {
	require_aws_provision_config

	local pulled_env="${BATS_TEST_TMPDIR}/aws-shared.env"

	run_deployer site:shared:pull \
		--domain="$AWS_TEST_SITE_DOMAIN" \
		--remote=".env" \
		--local="$pulled_env" \
		--yes

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "Shared file downloaded"
	assert_command_replay "site:shared:pull"
	[[ -f "$pulled_env" ]]

	run grep -q "APP_MESSAGE=$CLOUD_TEST_APP_MESSAGE" "$pulled_env"
	[ "$status" -eq 0 ]
}

# ----
# site:deploy
# ----

@test "site:deploy deploys application to AWS site" {
	require_aws_provision_config

	# Deploy takes time - use longer timeout
	run timeout 300 "$DEPLOYER_BIN" --inventory="$TEST_INVENTORY" --no-ansi site:deploy \
		--domain="$AWS_TEST_SITE_DOMAIN" \
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

@test "site:deploy deploys application to AWS secondary site" {
	require_aws_provision_config

	run timeout 300 "$DEPLOYER_BIN" --inventory="$TEST_INVENTORY" --no-ansi site:deploy \
		--domain="$AWS_TEST_SITE_DOMAIN_SECONDARY" \
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
# site:https
# ----

@test "site:https enables HTTPS for ${AWS_TEST_SITE_DOMAIN}" {
	require_aws_provision_config

	local server_ip
	server_ip=$(get_server_ip "$AWS_TEST_SERVER_NAME")

	[[ -n "$server_ip" ]] || skip "Could not determine server IP"
	wait_for_dns_a_record "$AWS_TEST_SITE_DOMAIN" "$server_ip" 300

	run timeout 300 "$DEPLOYER_BIN" --inventory="$TEST_INVENTORY" --no-ansi site:https \
		--domain="$AWS_TEST_SITE_DOMAIN"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "HTTPS enabled successfully"
	assert_command_replay "site:https"
}

@test "site:https enables HTTPS for ${AWS_TEST_SITE_DOMAIN_SECONDARY}" {
	require_aws_provision_config

	local server_ip
	server_ip=$(get_server_ip "$AWS_TEST_SERVER_NAME")

	[[ -n "$server_ip" ]] || skip "Could not determine server IP"
	wait_for_dns_a_record "$AWS_TEST_SITE_DOMAIN_SECONDARY" "$server_ip" 300

	run timeout 300 "$DEPLOYER_BIN" --inventory="$TEST_INVENTORY" --no-ansi site:https \
		--domain="$AWS_TEST_SITE_DOMAIN_SECONDARY"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "HTTPS enabled successfully"
	assert_command_replay "site:https"
}

# ----
# HTTP Verification
# ----

@test "deployed AWS site responds to HTTP requests after HTTPS setup" {
	require_aws_provision_config

	# DNS was already validated before site:https; verify app response after HTTPS setup.
	wait_for_http "$AWS_TEST_SITE_DOMAIN" "$CLOUD_TEST_APP_MESSAGE" 30
}

@test "deployed AWS secondary site responds to HTTP requests after HTTPS setup" {
	require_aws_provision_config

	wait_for_http "$AWS_TEST_SITE_DOMAIN_SECONDARY" "$CLOUD_TEST_APP_MESSAGE" 30
}

# ----
# site:rollback
# ----

@test "site:rollback shows forward-only deployment guidance" {
	require_aws_provision_config

	run_deployer site:rollback

	debug_output

	[ "$status" -eq 0 ]
	assert_info_output
	assert_output_contains "Forward-only deployments"
	assert_bullet_output
}

# ----
# site:delete
# ----

@test "site:delete removes ${AWS_TEST_SITE_DOMAIN_SECONDARY} from server and inventory" {
	require_aws_provision_config

	run_deployer site:delete \
		--domain="$AWS_TEST_SITE_DOMAIN_SECONDARY" \
		--force \
		--yes

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "removed from inventory"
	assert_command_replay "site:delete"
}

@test "site:delete removes ${AWS_TEST_SITE_DOMAIN} from server and inventory" {
	require_aws_provision_config

	run_deployer site:delete \
		--domain="$AWS_TEST_SITE_DOMAIN" \
		--force \
		--yes

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "removed from inventory"
	assert_command_replay "site:delete"
}

# ----
# cf:dns:delete
# ----

@test "cf:dns:delete removes prefixed A record ${CF_TEST_DNS_ROOT_FQDN}" {
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
# aws:dns:delete
# ----

@test "aws:dns:delete removes prefixed A record ${AWS_TEST_DNS_ROOT_SECONDARY_FQDN}" {
	require_aws_provision_config

	run_deployer aws:dns:delete \
		--zone="$AWS_TEST_HOSTED_ZONE" \
		--type="A" \
		--name="$AWS_TEST_DNS_ROOT_SECONDARY" \
		--force \
		--yes

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "DNS record deleted successfully"
	assert_command_replay "aws:dns:delete"
}

@test "aws:dns:delete removes prefixed A record ${AWS_TEST_DNS_ROOT_FQDN}" {
	require_aws_provision_config

	run_deployer aws:dns:delete \
		--zone="$AWS_TEST_HOSTED_ZONE" \
		--type="A" \
		--name="$AWS_TEST_DNS_ROOT" \
		--force \
		--yes

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "DNS record deleted successfully"
	assert_command_replay "aws:dns:delete"
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
	aws_assert_no_bats_volumes "$BATS_RUN_SUFFIX"
}
