#!/usr/bin/env bats

# DigitalOcean Integration Tests
# Tests: DigitalOcean key/provision/DNS + site lifecycle commands
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
	require_do_dns_prefix_config
	cloud_note "Run suffix: ${BATS_RUN_SUFFIX}"
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

	# Tag contract for janitor discovery
	do_assert_droplet_tag_contract "$DO_TEST_SERVER_NAME"
}

@test "server:install configures DigitalOcean provisioned server" {
	require_do_provision_config

	local primary_php_version secondary_php_version installed_php_versions
	primary_php_version="$CLOUD_TEST_PHP_PRIMARY_VERSION"
	secondary_php_version="$CLOUD_TEST_PHP_SECONDARY_VERSION"

	# Full install takes time - use longer timeout
	run timeout 600 "$DEPLOYER_BIN" --inventory="$TEST_INVENTORY" --no-ansi server:install \
		--server="$DO_TEST_SERVER_NAME" \
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
		--server="$DO_TEST_SERVER_NAME" \
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

	installed_php_versions="$(get_installed_php_fpm_versions_for_server "$DO_TEST_SERVER_NAME")"
	printf '%s\n' "$installed_php_versions" | grep -qx "$primary_php_version"
	printf '%s\n' "$installed_php_versions" | grep -qx "$secondary_php_version"
}

# ----
# site:create
# ----

@test "site:create creates site ${DO_TEST_SITE_DOMAIN} on DigitalOcean provisioned server" {
	require_do_provision_config

	# Cleanup any leftover test site
	cleanup_test_site "$DO_TEST_SITE_DOMAIN"

	run_deployer site:create \
		--domain="$DO_TEST_SITE_DOMAIN" \
		--server="$DO_TEST_SERVER_NAME" \
		--php-version="$CLOUD_TEST_PHP_PRIMARY_VERSION" \
		--web-root="/"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "added to inventory"
	assert_command_replay "site:create"
}

@test "site:create creates secondary site ${DO_TEST_SITE_DOMAIN_SECONDARY} on DigitalOcean provisioned server" {
	require_do_provision_config

	# Cleanup any leftover secondary test site
	cleanup_test_site "$DO_TEST_SITE_DOMAIN_SECONDARY"

	run_deployer site:create \
		--domain="$DO_TEST_SITE_DOMAIN_SECONDARY" \
		--server="$DO_TEST_SERVER_NAME" \
		--php-version="$CLOUD_TEST_PHP_SECONDARY_VERSION" \
		--web-root="/"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "added to inventory"
	assert_command_replay "site:create"
}

# ----
# do:dns:set
# ----

@test "do:dns:set creates prefixed A record for ${DO_TEST_DNS_ROOT_FQDN}" {
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

@test "do:dns:set creates secondary prefixed A record for ${DO_TEST_DNS_ROOT_SECONDARY_FQDN}" {
	require_do_provision_config

	local server_ip
	server_ip=$(get_server_ip "$DO_TEST_SERVER_NAME")

	[[ -n "$server_ip" ]] || skip "Could not determine server IP"

	run_deployer do:dns:set \
		--zone="$DO_TEST_DOMAIN" \
		--type="A" \
		--name="$DO_TEST_DNS_ROOT_SECONDARY" \
		--value="$server_ip" \
		--ttl="60"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "DNS record"
	assert_output_contains "successfully"
	assert_command_replay "do:dns:set"
}

@test "do:dns:list shows ${DO_TEST_DNS_ROOT_FQDN} and ${DO_TEST_DNS_ROOT_SECONDARY_FQDN}" {
	require_do_provision_config

	run_deployer do:dns:list \
		--zone="$DO_TEST_DOMAIN"

	debug_output

	[ "$status" -eq 0 ]
	assert_output_contains "$DO_TEST_DOMAIN"
	assert_output_contains "$DO_TEST_DNS_ROOT"
	assert_output_contains "$DO_TEST_DNS_ROOT_SECONDARY"
	assert_command_replay "do:dns:list"
}

# ----
# site:dns:check
# ----

@test "site:dns:check resolves DNS for ${DO_TEST_SITE_DOMAIN}" {
	require_do_provision_config

	local server_ip
	server_ip=$(get_server_ip "$DO_TEST_SERVER_NAME")

	[[ -n "$server_ip" ]] || skip "Could not determine server IP"

	run_deployer site:dns:check \
		--domain="$DO_TEST_SITE_DOMAIN"

	debug_output

	[ "$status" -eq 0 ]
	assert_output_contains "Check DNS"
	assert_output_contains "Domain: $DO_TEST_SITE_DOMAIN"
	assert_output_contains "A:"
	assert_output_contains "AAAA:"
	assert_output_contains "$server_ip"
	assert_command_replay "site:dns:check"
}

@test "site:dns:check resolves DNS for ${DO_TEST_SITE_DOMAIN_SECONDARY}" {
	require_do_provision_config

	local server_ip
	server_ip=$(get_server_ip "$DO_TEST_SERVER_NAME")

	[[ -n "$server_ip" ]] || skip "Could not determine server IP"

	run_deployer site:dns:check \
		--domain="$DO_TEST_SITE_DOMAIN_SECONDARY"

	debug_output

	[ "$status" -eq 0 ]
	assert_output_contains "Check DNS"
	assert_output_contains "Domain: $DO_TEST_SITE_DOMAIN_SECONDARY"
	assert_output_contains "A:"
	assert_output_contains "AAAA:"
	assert_output_contains "$server_ip"
	assert_command_replay "site:dns:check"
}

# ----
# site:shared:push
# ----

@test "site:shared:push uploads .env to DigitalOcean site" {
	require_do_provision_config

	run_deployer site:shared:push \
		--domain="$DO_TEST_SITE_DOMAIN" \
		--local="${BATS_TEST_ROOT}/fixtures/env/deploy-me.env" \
		--remote=".env"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "Shared file uploaded"
	assert_command_replay "site:shared:push"
}

@test "site:shared:push uploads .env to DigitalOcean secondary site" {
	require_do_provision_config

	run_deployer site:shared:push \
		--domain="$DO_TEST_SITE_DOMAIN_SECONDARY" \
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

@test "site:shared:list shows uploaded shared files for DigitalOcean site" {
	require_do_provision_config

	run_deployer site:shared:list \
		--domain="$DO_TEST_SITE_DOMAIN"

	debug_output

	[ "$status" -eq 0 ]
	assert_output_contains ".env"
	assert_command_replay "site:shared:list"
}

# ----
# site:shared:pull
# ----

@test "site:shared:pull downloads .env from DigitalOcean site" {
	require_do_provision_config

	local pulled_env="${BATS_TEST_TMPDIR}/do-shared.env"

	run_deployer site:shared:pull \
		--domain="$DO_TEST_SITE_DOMAIN" \
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

@test "site:deploy deploys application to DigitalOcean site" {
	require_do_provision_config

	# Deploy takes time - use longer timeout
	run timeout 300 "$DEPLOYER_BIN" --inventory="$TEST_INVENTORY" --no-ansi site:deploy \
		--domain="$DO_TEST_SITE_DOMAIN" \
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

@test "site:deploy deploys application to DigitalOcean secondary site" {
	require_do_provision_config

	run timeout 300 "$DEPLOYER_BIN" --inventory="$TEST_INVENTORY" --no-ansi site:deploy \
		--domain="$DO_TEST_SITE_DOMAIN_SECONDARY" \
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
# cron/supervisor
# ----

@test "cron:create adds hello.sh cron for DigitalOcean site" {
	require_do_provision_config

	run_deployer cron:create \
		--domain="$DO_TEST_SITE_DOMAIN" \
		--script="$CLOUD_TEST_CRON_SUPERVISOR_SCRIPT" \
		--schedule="* * * * *"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "added to inventory"
	assert_command_replay "cron:create"
}

@test "cron:sync applies hello.sh cron to DigitalOcean server" {
	require_do_provision_config

	run_deployer cron:sync \
		--domain="$DO_TEST_SITE_DOMAIN"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "synced to server"
	assert_command_replay "cron:sync"
}

@test "cron:sync writes DigitalOcean crontab entry and log file for hello.sh" {
	require_do_provision_config

	run_deployer server:run \
		--server="$DO_TEST_SERVER_NAME" \
		--command="sudo -n crontab -l -u deployer | grep -F 'runner.sh $CLOUD_TEST_CRON_SUPERVISOR_SCRIPT'"

	debug_output

	[ "$status" -eq 0 ]
	assert_output_contains "runner.sh $CLOUD_TEST_CRON_SUPERVISOR_SCRIPT"

	run_deployer server:run \
		--server="$DO_TEST_SERVER_NAME" \
		--command="test -f /var/log/cron/$DO_TEST_SITE_DOMAIN-$CLOUD_TEST_CRON_SUPERVISOR_SCRIPT.log && echo cron-log-found"

	debug_output

	[ "$status" -eq 0 ]
	assert_output_contains "cron-log-found"
}

@test "supervisor:create adds hello.sh program for DigitalOcean site" {
	require_do_provision_config

	run_deployer supervisor:create \
		--domain="$DO_TEST_SITE_DOMAIN" \
		--program="$CLOUD_TEST_SUPERVISOR_PROGRAM" \
		--script="$CLOUD_TEST_CRON_SUPERVISOR_SCRIPT" \
		--no-autostart \
		--no-autorestart \
		--stopwaitsecs="10" \
		--numprocs="1"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "added to inventory"
	assert_command_replay "supervisor:create"
}

@test "supervisor:sync applies hello.sh program to DigitalOcean server" {
	require_do_provision_config

	run_deployer supervisor:sync \
		--domain="$DO_TEST_SITE_DOMAIN"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "synced to server"
	assert_command_replay "supervisor:sync"
}

@test "supervisor:sync writes DigitalOcean supervisor config for hello.sh" {
	require_do_provision_config

	run_deployer server:run \
		--server="$DO_TEST_SERVER_NAME" \
		--command="test -f /etc/supervisor/conf.d/$DO_TEST_SITE_DOMAIN-$CLOUD_TEST_SUPERVISOR_PROGRAM.conf && grep -F 'runner.sh $CLOUD_TEST_CRON_SUPERVISOR_SCRIPT' /etc/supervisor/conf.d/$DO_TEST_SITE_DOMAIN-$CLOUD_TEST_SUPERVISOR_PROGRAM.conf && echo supervisor-config-found"

	debug_output

	[ "$status" -eq 0 ]
	assert_output_contains "supervisor-config-found"
}

@test "supervisor lifecycle commands restart/stop/start work on DigitalOcean server" {
	require_do_provision_config

	run_deployer supervisor:restart \
		--server="$DO_TEST_SERVER_NAME"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_command_replay "supervisor:restart"

	run_deployer supervisor:stop \
		--server="$DO_TEST_SERVER_NAME"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_command_replay "supervisor:stop"

	run_deployer supervisor:start \
		--server="$DO_TEST_SERVER_NAME"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_command_replay "supervisor:start"
}

@test "cron:delete removes hello.sh cron for DigitalOcean site" {
	require_do_provision_config

	run_deployer cron:delete \
		--domain="$DO_TEST_SITE_DOMAIN" \
		--script="$CLOUD_TEST_CRON_SUPERVISOR_SCRIPT" \
		--force \
		--yes

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "removed from inventory"
	assert_command_replay "cron:delete"
}

@test "cron:sync removes hello.sh crontab entry from DigitalOcean server" {
	require_do_provision_config

	run_deployer cron:sync \
		--domain="$DO_TEST_SITE_DOMAIN"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_command_replay "cron:sync"

	run_deployer server:run \
		--server="$DO_TEST_SERVER_NAME" \
		--command="if sudo -n crontab -l -u deployer 2>/dev/null | grep -Fq 'runner.sh $CLOUD_TEST_CRON_SUPERVISOR_SCRIPT'; then echo cron-entry-present; exit 1; else echo cron-entry-absent; fi"

	debug_output

	[ "$status" -eq 0 ]
	assert_output_contains "cron-entry-absent"
}

@test "supervisor:delete removes hello.sh program for DigitalOcean site" {
	require_do_provision_config

	run_deployer supervisor:delete \
		--domain="$DO_TEST_SITE_DOMAIN" \
		--program="$CLOUD_TEST_SUPERVISOR_PROGRAM" \
		--force \
		--yes

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "removed from inventory"
	assert_command_replay "supervisor:delete"
}

@test "supervisor:sync removes hello.sh supervisor config from DigitalOcean server" {
	require_do_provision_config

	run_deployer supervisor:sync \
		--domain="$DO_TEST_SITE_DOMAIN"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_command_replay "supervisor:sync"

	run_deployer server:run \
		--server="$DO_TEST_SERVER_NAME" \
		--command="if test -f /etc/supervisor/conf.d/$DO_TEST_SITE_DOMAIN-$CLOUD_TEST_SUPERVISOR_PROGRAM.conf; then echo supervisor-config-present; exit 1; else echo supervisor-config-absent; fi"

	debug_output

	[ "$status" -eq 0 ]
	assert_output_contains "supervisor-config-absent"
}

# ----
# site:https
# ----

@test "site:https enables HTTPS for ${DO_TEST_SITE_DOMAIN}" {
	require_do_provision_config

	run timeout 300 "$DEPLOYER_BIN" --inventory="$TEST_INVENTORY" --no-ansi site:https \
		--domain="$DO_TEST_SITE_DOMAIN"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "HTTPS enabled successfully"
	assert_command_replay "site:https"
}

@test "site:https enables HTTPS for ${DO_TEST_SITE_DOMAIN_SECONDARY}" {
	require_do_provision_config

	run timeout 300 "$DEPLOYER_BIN" --inventory="$TEST_INVENTORY" --no-ansi site:https \
		--domain="$DO_TEST_SITE_DOMAIN_SECONDARY"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "HTTPS enabled successfully"
	assert_command_replay "site:https"
}

# ----
# HTTP Verification
# ----

@test "deployed DigitalOcean site responds to HTTP requests after HTTPS setup" {
	require_do_provision_config

	# Verify app response after HTTPS setup.
	wait_for_http "$DO_TEST_SITE_DOMAIN" "$CLOUD_TEST_APP_MESSAGE" 30
}

@test "deployed DigitalOcean secondary site responds to HTTP requests after HTTPS setup" {
	require_do_provision_config

	wait_for_http "$DO_TEST_SITE_DOMAIN_SECONDARY" "$CLOUD_TEST_APP_MESSAGE" 30
}

# ----
# site:rollback
# ----

@test "site:rollback shows forward-only deployment guidance" {
	require_do_provision_config

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

@test "site:delete removes ${DO_TEST_SITE_DOMAIN_SECONDARY} from server and inventory" {
	require_do_provision_config

	run_deployer site:delete \
		--domain="$DO_TEST_SITE_DOMAIN_SECONDARY" \
		--force \
		--yes

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "removed from inventory"
	assert_command_replay "site:delete"
}

@test "site:delete removes ${DO_TEST_SITE_DOMAIN} from server and inventory" {
	require_do_provision_config

	run_deployer site:delete \
		--domain="$DO_TEST_SITE_DOMAIN" \
		--force \
		--yes

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "removed from inventory"
	assert_command_replay "site:delete"
}

# ----
# do:dns:delete
# ----

@test "do:dns:delete removes prefixed A record ${DO_TEST_DNS_ROOT_SECONDARY_FQDN}" {
	require_do_provision_config

	run_deployer do:dns:delete \
		--zone="$DO_TEST_DOMAIN" \
		--type="A" \
		--name="$DO_TEST_DNS_ROOT_SECONDARY" \
		--force \
		--yes

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "DNS record deleted successfully"
	assert_command_replay "do:dns:delete"
}

@test "do:dns:delete removes prefixed A record ${DO_TEST_DNS_ROOT_FQDN}" {
	require_do_provision_config

	run_deployer do:dns:delete \
		--zone="$DO_TEST_DOMAIN" \
		--type="A" \
		--name="$DO_TEST_DNS_ROOT" \
		--force \
		--yes

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "DNS record deleted successfully"
	assert_command_replay "do:dns:delete"
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
