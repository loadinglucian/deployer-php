#!/usr/bin/env bats

# VM command tests (server:add, server:info, etc.)
# Tests: server:add, server:info, server:delete, server:install, server:firewall, server:logs,
# server:run, mariadb:install, postgresql:install, redis:install, memcached:install,
# server:install second PHP version,
# nginx:start|stop|restart, php:start|stop|restart, mariadb:start|stop|restart,
# postgresql:start|stop|restart, redis:start|stop|restart, memcached:start|stop|restart,
# supervisor:start|stop|restart

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
# Install Test Helpers
# ----

run_deployer_timeout() {
	local seconds="$1"
	shift
	run timeout "$seconds" "$DEPLOYER_BIN" --inventory="$TEST_INVENTORY" --no-ansi "$@"
}

assert_file_mode_600() {
	local path="$1"
	local mode
	mode=$(stat -c '%a' "$path" 2> /dev/null || stat -f '%Lp' "$path" 2> /dev/null)

	if [[ "$mode" != "600" ]]; then
		echo "Expected file mode 600 for ${path}, got ${mode}"
		return 1
	fi
}

read_env_value() {
	local path="$1"
	local key="$2"
	grep "^${key}=" "$path" | tail -1 | cut -d'=' -f2-
}

assert_env_key_present() {
	local path="$1"
	local key="$2"
	if ! grep -q "^${key}=" "$path"; then
		echo "Expected key ${key} in ${path}"
		return 1
	fi
}

cleanup_local_credential_file() {
	local path="$1"
	rm -f "$path"
}

cleanup_sql_stack() {
	ssh_exec "
		export DEBIAN_FRONTEND=noninteractive
		systemctl stop mariadb 2>/dev/null || true
		dpkg -l | awk '/^ii/ && (\$2 ~ /^mariadb/) {print \$2}' \
			| xargs -r apt-get purge -y > /dev/null 2>&1 || true
		apt-get autoremove -y > /dev/null 2>&1 || true
		rm -rf /etc/mysql /var/lib/mysql /var/log/mysql 2> /dev/null || true
		rm -f /etc/apt/sources.list.d/mariadb.list /etc/apt/keyrings/mariadb.gpg 2>/dev/null || true
	"
}

cleanup_kv_stack() {
	ssh_exec "
		export DEBIAN_FRONTEND=noninteractive
		systemctl stop redis redis-server 2>/dev/null || true
		dpkg -l | awk '/^ii/ && (\$2 ~ /^redis/) {print \$2}' \
			| xargs -r apt-get purge -y > /dev/null 2>&1 || true
		apt-get autoremove -y > /dev/null 2>&1 || true
		rm -rf /etc/redis /var/lib/redis /var/log/redis 2> /dev/null || true
		rm -f /etc/apt/sources.list.d/redis.list /etc/apt/keyrings/redis.gpg 2>/dev/null || true
	"
}

cleanup_postgresql_stack() {
	ssh_exec "
		export DEBIAN_FRONTEND=noninteractive
		systemctl stop postgresql 2>/dev/null || true
		dpkg -l | awk '/^ii/ && (\$2 ~ /^postgresql/) {print \$2}' \
			| xargs -r apt-get purge -y > /dev/null 2>&1 || true
		apt-get autoremove -y > /dev/null 2>&1 || true
		rm -rf /etc/postgresql /var/lib/postgresql /var/log/postgresql 2> /dev/null || true
		rm -f /etc/apt/sources.list.d/postgresql.list /etc/apt/keyrings/postgresql.gpg 2>/dev/null || true
	"
}

assert_remote_service_inactive() {
	local service="$1"
	if ssh_exec "systemctl is-active --quiet '${service}'"; then
		echo "Expected service to be inactive: ${service}"
		return 1
	fi
}

assert_remote_service_active() {
	local service="$1"
	if ! ssh_exec "systemctl is-active --quiet '${service}'"; then
		echo "Expected service to be active: ${service}"
		return 1
	fi
}

assert_lifecycle_command_success() {
	local command="$1"
	shift

	run_deployer_timeout 180 "$command" \
		--server="$TEST_SERVER_NAME" \
		"$@"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_command_replay "$command"
}

get_installed_php_fpm_versions() {
	ssh_exec "ls -1 /etc/php/*/fpm/php-fpm.conf 2>/dev/null | sed -nE 's|/etc/php/([^/]+)/fpm/php-fpm.conf|\\1|p' | sort -Vr | uniq"
}

extract_display_connection_string() {
	local scheme="$1"
	printf '%s\n' "$output" | sed -nE "s|.*(${scheme}://[^[:space:]]+).*|\\1|p" | tail -1
}

extract_sql_root_password_from_display() {
	printf '%s\n' "$output" | awk '
		/Root Credentials \(admin access\):/ { in_section=1; next }
		in_section && /Application Credentials:/ { in_section=0 }
		in_section && /Password:[[:space:]]*/ {
			line = $0;
			sub(/^.*Password:[[:space:]]*/, "", line);
			print line;
			exit;
		}
	'
}

extract_postgres_root_password_from_display() {
	printf '%s\n' "$output" | awk '
		/Postgres Credentials \(admin access\):/ { in_section=1; next }
		in_section && /Application Credentials:/ { in_section=0 }
		in_section && /Password:[[:space:]]*/ {
			line = $0;
			sub(/^.*Password:[[:space:]]*/, "", line);
			print line;
			exit;
		}
	'
}

extract_sql_username_from_dsn() {
	local dsn="$1"
	local rest
	rest="${dsn#*://}"
	printf '%s\n' "${rest%%:*}"
}

extract_sql_password_from_dsn() {
	local dsn="$1"
	local rest after_user
	rest="${dsn#*://}"
	after_user="${rest#*:}"
	printf '%s\n' "${after_user%@localhost/*}"
}

extract_sql_database_from_dsn() {
	local dsn="$1"
	local db_part
	db_part="${dsn#*@localhost/}"
	printf '%s\n' "${db_part%%\?*}"
}

extract_kv_password_from_dsn() {
	local dsn="$1"
	printf '%s\n' "$dsn" | sed -nE 's|^redis://:([^@]+)@.*$|\1|p'
}

assert_sql_auth_via_credentials() {
	local root_pass="$1"
	local db_user="$2"
	local db_pass="$3"
	local db_name="$4"
	local client_bin="$5"

	ssh_exec "MYSQL_PWD='${root_pass}' ${client_bin} -u root -e 'SELECT 1;' > /dev/null"
	ssh_exec "MYSQL_PWD='${db_pass}' ${client_bin} -u '${db_user}' -D '${db_name}' -e 'SELECT 1;' > /dev/null"
}

assert_postgresql_auth_via_credentials() {
	local postgres_pass="$1"
	local db_user="$2"
	local db_pass="$3"
	local db_name="$4"

	ssh_exec "PGPASSWORD='${postgres_pass}' psql -h localhost -U postgres -d postgres -c 'SELECT 1;' > /dev/null"
	ssh_exec "PGPASSWORD='${db_pass}' psql -h localhost -U '${db_user}' -d '${db_name}' -c 'SELECT 1;' > /dev/null"
}

assert_kv_auth_via_credentials() {
	local kv_pass="$1"
	local client_bin="$2"

	ssh_exec "${client_bin} -a '${kv_pass}' ping | grep -q '^PONG$'"
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
		--no-destroy-cloud

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "removed"
	! inventory_has_server "$TEST_SERVER_NAME"
}

@test "server:delete with --no-destroy-cloud removes from inventory" {
	add_test_server "no-destroy-server"
	inventory_has_server "no-destroy-server"

	run_deployer server:delete \
		--server="no-destroy-server" \
		--force \
		--yes \
		--no-destroy-cloud

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	! inventory_has_server "no-destroy-server"
}

# NOTE: "server:delete fails when typed name doesn't match" cannot be tested
# in BATS because Laravel Prompts doesn't support piped stdin in non-TTY mode.
# The type-to-confirm logic is tested manually or requires expect/pty tooling.

# ----
# server:install
# ----

@test "server:install completes successfully with generated deploy key" {
	add_test_server

	local primary_php_version="${VM_TEST_PHP_PRIMARY_VERSION:-8.5}"

	# Full install takes time - use longer timeout
	run timeout 300 "$DEPLOYER_BIN" --inventory="$TEST_INVENTORY" --no-ansi server:install \
		--server="$TEST_SERVER_NAME" \
		--generate-deploy-key \
		--timezone="UTC" \
		--php-version="$primary_php_version" \
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

@test "server:install installs Nginx web server" {
	add_test_server

	# Command failure fails the test
	ssh_exec "command -v nginx"
}

@test "server:install creates Nginx config structure" {
	add_test_server

	assert_remote_dir_exists "/etc/nginx/sites-enabled"
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

	local primary_php_version secondary_php_version
	primary_php_version="${VM_TEST_PHP_PRIMARY_VERSION:-8.5}"
	secondary_php_version="${VM_TEST_PHP_SECONDARY_VERSION:-8.4}"

	# Get the public key content from our test key
	local expected_key
	expected_key=$(cat "${TEST_KEY}.pub")

	# Run install with custom key (cli,fpm are always installed, must include optional extension)
	run timeout 300 "$DEPLOYER_BIN" --inventory="$TEST_INVENTORY" --no-ansi server:install \
		--server="$TEST_SERVER_NAME" \
		--custom-deploy-key="$TEST_KEY" \
		--timezone="UTC" \
		--php-version="$secondary_php_version" \
		--no-php-default \
		--php-extensions="cli,fpm,curl,mbstring"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output

	# Verify the remote key matches our test key
	local remote_key
	remote_key=$(ssh_exec "cat /home/deployer/.ssh/id_ed25519.pub")
	[[ "$remote_key" == "$expected_key" ]]

	# Verify both versions are now installed
	local installed_php_versions
	installed_php_versions="$(get_installed_php_fpm_versions)"
	printf '%s\n' "$installed_php_versions" | grep -qx "$primary_php_version"
	printf '%s\n' "$installed_php_versions" | grep -qx "$secondary_php_version"
}

# ----
# server:firewall
# ----

@test "server:firewall configures UFW with listening port" {
	add_test_server

	# After server:install, Nginx stub_status listens on port 8080
	# Port 80 only listens after site:create creates a vhost
	run_deployer server:firewall \
		--server="$TEST_SERVER_NAME" \
		--allow="8080" \
		--yes

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "Firewall configured successfully"
	assert_command_replay "server:firewall"
}

@test "server:firewall verifies UFW is enabled on server" {
	add_test_server

	# UFW should be enabled by server:install (base-install.sh)
	local ufw_status
	ufw_status=$(ssh_exec "ufw status")
	[[ "$ufw_status" =~ "Status: active" ]]
}

# ----
# server:logs
# ----

@test "server:logs retrieves system logs" {
	add_test_server

	run_deployer server:logs \
		--server="$TEST_SERVER_NAME" \
		--service="system" \
		--lines=10

	debug_output

	[ "$status" -eq 0 ]
	assert_output_contains "System logs"
	assert_command_replay "server:logs"
}

@test "server:logs retrieves multiple service logs" {
	add_test_server

	run_deployer server:logs \
		--server="$TEST_SERVER_NAME" \
		--service="system,cron" \
		--lines=5

	debug_output

	[ "$status" -eq 0 ]
	assert_output_contains "System logs"
	assert_output_contains "Cron"
}

# ----
# server:run
# ----

@test "server:run executes command on server" {
	add_test_server

	run_deployer server:run \
		--server="$TEST_SERVER_NAME" \
		--command="whoami"

	debug_output

	[ "$status" -eq 0 ]
	assert_output_contains "root"
	assert_command_replay "server:run"
}

@test "server:run shows command output" {
	add_test_server

	run_deployer server:run \
		--server="$TEST_SERVER_NAME" \
		--command="echo hello-deployer-test"

	debug_output

	[ "$status" -eq 0 ]
	assert_output_contains "hello-deployer-test"
}

# ----
# install command happy paths
# ----

@test "mariadb:install saves credentials file and authenticates" {
	add_test_server
	cleanup_sql_stack

	local creds_file="${BATS_TEST_TMPDIR}/mariadb.credentials"
	cleanup_local_credential_file "$creds_file"

	run_deployer_timeout 540 mariadb:install \
		--server="$TEST_SERVER_NAME" \
		--save-credentials="$creds_file"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "MariaDB installation completed successfully"
	assert_command_replay "mariadb:install"
	assert_output_contains "--save-credentials='${creds_file}'"
	[ -f "$creds_file" ]
	assert_file_mode_600 "$creds_file"
	assert_env_key_present "$creds_file" "MARIADB_ROOT_PASSWORD"
	assert_env_key_present "$creds_file" "MARIADB_USER"
	assert_env_key_present "$creds_file" "MARIADB_PASSWORD"
	assert_env_key_present "$creds_file" "DATABASE_URL"

	local root_pass mariadb_user mariadb_pass mariadb_database
	root_pass=$(read_env_value "$creds_file" "MARIADB_ROOT_PASSWORD")
	mariadb_user=$(read_env_value "$creds_file" "MARIADB_USER")
	mariadb_pass=$(read_env_value "$creds_file" "MARIADB_PASSWORD")
	mariadb_database=$(read_env_value "$creds_file" "MARIADB_DATABASE")

	[[ -n "$root_pass" ]]
	[[ -n "$mariadb_user" ]]
	[[ -n "$mariadb_pass" ]]
	[[ -n "$mariadb_database" ]]

	assert_sql_auth_via_credentials "$root_pass" "$mariadb_user" "$mariadb_pass" "$mariadb_database" "mariadb"
}

@test "mariadb:install displays credentials and authenticates" {
	add_test_server
	cleanup_sql_stack

	run_deployer_timeout 540 mariadb:install \
		--server="$TEST_SERVER_NAME" \
		--display-credentials

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "MariaDB installation completed successfully"
	assert_output_contains "Root Credentials (admin access):"
	assert_output_contains "Connection string:"
	assert_command_replay "mariadb:install"
	assert_output_contains "--display-credentials"

	local mariadb_dsn root_pass mariadb_user mariadb_pass mariadb_database
	mariadb_dsn=$(extract_display_connection_string "mysql")
	root_pass=$(extract_sql_root_password_from_display)
	mariadb_user=$(extract_sql_username_from_dsn "$mariadb_dsn")
	mariadb_pass=$(extract_sql_password_from_dsn "$mariadb_dsn")
	mariadb_database=$(extract_sql_database_from_dsn "$mariadb_dsn")

	[[ -n "$mariadb_dsn" ]]
	[[ -n "$root_pass" ]]
	[[ -n "$mariadb_user" ]]
	[[ -n "$mariadb_pass" ]]
	[[ -n "$mariadb_database" ]]

	assert_sql_auth_via_credentials "$root_pass" "$mariadb_user" "$mariadb_pass" "$mariadb_database" "mariadb"
}

@test "postgresql:install saves credentials file and authenticates" {
	add_test_server
	cleanup_postgresql_stack
	assert_remote_service_inactive "postgresql"

	local creds_file="${BATS_TEST_TMPDIR}/postgresql.credentials"
	cleanup_local_credential_file "$creds_file"

	run_deployer_timeout 540 postgresql:install \
		--server="$TEST_SERVER_NAME" \
		--save-credentials="$creds_file"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "PostgreSQL installation completed successfully"
	assert_command_replay "postgresql:install"
	assert_output_contains "--save-credentials='${creds_file}'"
	[ -f "$creds_file" ]
	assert_file_mode_600 "$creds_file"
	assert_env_key_present "$creds_file" "POSTGRES_PASSWORD"
	assert_env_key_present "$creds_file" "POSTGRES_USER"
	assert_env_key_present "$creds_file" "POSTGRES_USER_PASSWORD"
	assert_env_key_present "$creds_file" "DATABASE_URL"

	local postgres_pass postgres_user postgres_user_pass postgres_database
	postgres_pass=$(read_env_value "$creds_file" "POSTGRES_PASSWORD")
	postgres_user=$(read_env_value "$creds_file" "POSTGRES_USER")
	postgres_user_pass=$(read_env_value "$creds_file" "POSTGRES_USER_PASSWORD")
	postgres_database=$(read_env_value "$creds_file" "POSTGRES_DATABASE")

	[[ -n "$postgres_pass" ]]
	[[ -n "$postgres_user" ]]
	[[ -n "$postgres_user_pass" ]]
	[[ -n "$postgres_database" ]]

	assert_postgresql_auth_via_credentials "$postgres_pass" "$postgres_user" "$postgres_user_pass" "$postgres_database"
}

@test "postgresql:install displays credentials and authenticates" {
	add_test_server
	cleanup_postgresql_stack
	assert_remote_service_inactive "postgresql"

	run_deployer_timeout 540 postgresql:install \
		--server="$TEST_SERVER_NAME" \
		--display-credentials

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "PostgreSQL installation completed successfully"
	assert_output_contains "Postgres Credentials (admin access):"
	assert_output_contains "Connection string:"
	assert_command_replay "postgresql:install"
	assert_output_contains "--display-credentials"

	local postgres_dsn postgres_pass postgres_user postgres_user_pass postgres_database
	postgres_dsn=$(extract_display_connection_string "postgresql")
	postgres_pass=$(extract_postgres_root_password_from_display)
	postgres_user=$(extract_sql_username_from_dsn "$postgres_dsn")
	postgres_user_pass=$(extract_sql_password_from_dsn "$postgres_dsn")
	postgres_database=$(extract_sql_database_from_dsn "$postgres_dsn")

	[[ -n "$postgres_dsn" ]]
	[[ -n "$postgres_pass" ]]
	[[ -n "$postgres_user" ]]
	[[ -n "$postgres_user_pass" ]]
	[[ -n "$postgres_database" ]]

	assert_postgresql_auth_via_credentials "$postgres_pass" "$postgres_user" "$postgres_user_pass" "$postgres_database"
}

@test "redis:install saves credentials file and authenticates" {
	add_test_server
	cleanup_kv_stack

	local creds_file="${BATS_TEST_TMPDIR}/redis.credentials"
	cleanup_local_credential_file "$creds_file"

	run_deployer_timeout 300 redis:install \
		--server="$TEST_SERVER_NAME" \
		--save-credentials="$creds_file"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "Redis installation completed successfully"
	assert_command_replay "redis:install"
	assert_output_contains "--save-credentials='${creds_file}'"
	[ -f "$creds_file" ]
	assert_file_mode_600 "$creds_file"
	assert_env_key_present "$creds_file" "REDIS_PASSWORD"
	assert_env_key_present "$creds_file" "REDIS_URL"

	local redis_pass
	redis_pass=$(read_env_value "$creds_file" "REDIS_PASSWORD")

	[[ -n "$redis_pass" ]]

	assert_kv_auth_via_credentials "$redis_pass" "redis-cli"
}

@test "redis:install displays credentials and authenticates" {
	add_test_server
	cleanup_kv_stack

	run_deployer_timeout 300 redis:install \
		--server="$TEST_SERVER_NAME" \
		--display-credentials

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "Redis installation completed successfully"
	assert_output_contains "Redis Password:"
	assert_output_contains "Connection string:"
	assert_command_replay "redis:install"
	assert_output_contains "--display-credentials"

	local redis_dsn redis_pass
	redis_dsn=$(extract_display_connection_string "redis")
	redis_pass=$(extract_kv_password_from_dsn "$redis_dsn")

	[[ -n "$redis_dsn" ]]
	[[ -n "$redis_pass" ]]

	assert_kv_auth_via_credentials "$redis_pass" "redis-cli"
}

@test "memcached:install completes successfully and configures localhost-only access" {
	add_test_server

	run_deployer_timeout 300 memcached:install \
		--server="$TEST_SERVER_NAME"

	debug_output

	[ "$status" -eq 0 ]
	assert_success_output
	assert_output_contains "Memcached installation completed successfully"
	assert_output_contains "Memcached does not generate credentials"
	assert_command_replay "memcached:install"

	ssh_exec "systemctl is-active --quiet memcached"
	ssh_exec "grep -q '^-l 127.0.0.1' /etc/memcached.conf"
}

# ----
# service lifecycle commands
# ----

@test "nginx lifecycle commands stop/start/restart work" {
	add_test_server

	assert_lifecycle_command_success "nginx:restart"
	assert_remote_service_active "nginx"

	assert_lifecycle_command_success "nginx:stop"
	assert_remote_service_inactive "nginx"

	assert_lifecycle_command_success "nginx:start"
	assert_remote_service_active "nginx"
}

@test "php lifecycle commands stop/start/restart work for at least two versions" {
	add_test_server

	local php_version php_service
	mapfile -t installed_php_versions < <(get_installed_php_fpm_versions)
	[[ "${#installed_php_versions[@]}" -ge 2 ]]

	for php_version in "${installed_php_versions[@]:0:2}"; do
		php_service="php${php_version}-fpm"

		assert_lifecycle_command_success "php:restart" --php-version="$php_version"
		assert_remote_service_active "$php_service"

		assert_lifecycle_command_success "php:stop" --php-version="$php_version"
		assert_remote_service_inactive "$php_service"

		assert_lifecycle_command_success "php:start" --php-version="$php_version"
		assert_remote_service_active "$php_service"
	done
}

@test "mariadb lifecycle commands stop/start/restart work" {
	add_test_server

	assert_lifecycle_command_success "mariadb:restart"
	assert_remote_service_active "mariadb"

	assert_lifecycle_command_success "mariadb:stop"
	assert_remote_service_inactive "mariadb"

	assert_lifecycle_command_success "mariadb:start"
	assert_remote_service_active "mariadb"
}

@test "postgresql lifecycle commands stop/start/restart work" {
	add_test_server

	assert_lifecycle_command_success "postgresql:restart"
	assert_remote_service_active "postgresql"

	assert_lifecycle_command_success "postgresql:stop"
	assert_remote_service_inactive "postgresql"

	assert_lifecycle_command_success "postgresql:start"
	assert_remote_service_active "postgresql"
}

@test "redis lifecycle commands stop/start/restart work" {
	add_test_server

	assert_lifecycle_command_success "redis:restart"
	assert_remote_service_active "redis-server"

	assert_lifecycle_command_success "redis:stop"
	assert_remote_service_inactive "redis-server"

	assert_lifecycle_command_success "redis:start"
	assert_remote_service_active "redis-server"
}

@test "memcached lifecycle commands stop/start/restart work" {
	add_test_server

	assert_lifecycle_command_success "memcached:restart"
	assert_remote_service_active "memcached"

	assert_lifecycle_command_success "memcached:stop"
	assert_remote_service_inactive "memcached"

	assert_lifecycle_command_success "memcached:start"
	assert_remote_service_active "memcached"
}

@test "supervisor lifecycle commands stop/start/restart work" {
	add_test_server

	assert_lifecycle_command_success "supervisor:restart"
	assert_remote_service_active "supervisor"

	assert_lifecycle_command_success "supervisor:stop"
	assert_remote_service_inactive "supervisor"

	assert_lifecycle_command_success "supervisor:start"
	assert_remote_service_active "supervisor"
}

# ----
# server:ssh
# ----

# NOTE: server:ssh cannot be tested in BATS because it uses pcntl_exec() to
# replace the PHP process with an SSH session. Control never returns to BATS
# after successful execution, making automated testing impossible.
