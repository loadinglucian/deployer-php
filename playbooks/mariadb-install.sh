#!/usr/bin/env bash

#
# MariaDB Installation
#
# Installs MariaDB server, configures root password, and creates deployer database/user.
#
# Output (fresh install):
#   status: success
#   root_pass: Ek3jF8mNpQ2rS5tV
#   deployer_user: deployer
#   deployer_pass: Wx7yZ9aBcD4eF6gH
#   deployer_database: deployer
#
# Output (already installed):
#   status: success
#   already_installed: true
#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_DISTRO ]] && echo "Error: DEPLOYER_DISTRO required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
export DEPLOYER_PERMS

# Shared helpers are automatically inlined when executing playbooks remotely
# source "$(dirname "$0")/helpers.sh"

# Credentials (generated in main, used across functions)
ROOT_PASS=""
DEPLOYER_USER="deployer"
DEPLOYER_PASS=""
DEPLOYER_DATABASE="deployer"

# ----
# Conflict Detection
# ----

#
# Check for MySQL conflict before installation

check_mysql_conflict() {
	# Check if MySQL service is running
	if systemctl is-active --quiet mysql 2> /dev/null || systemctl is-active --quiet mysqld 2> /dev/null; then
		echo "Error: MySQL is already installed and running on this server" >&2
		echo "Both MySQL and MariaDB use port 3306 and cannot coexist." >&2
		echo "Please uninstall MySQL first if you want to use MariaDB." >&2
		exit 1
	fi

	# Check if MySQL packages are installed (even if service is stopped)
	if dpkg -l mysql-server 2> /dev/null | grep -q '^ii'; then
		echo "Error: MySQL packages are installed on this server" >&2
		echo "Both MySQL and MariaDB use port 3306 and cannot coexist." >&2
		echo "Please remove MySQL packages first: apt-get purge mysql-server mysql-client" >&2
		exit 1
	fi
}

# ----
# Installation Functions
# ----

#
# Package Installation
# ----

#
# Install MariaDB packages

install_packages() {
	echo "→ Installing MariaDB..."

	local packages=(mariadb-server mariadb-client)

	if ! apt_get_with_retry install -y "${packages[@]}" 2>&1; then
		echo "Error: Failed to install MariaDB packages" >&2
		exit 1
	fi

	# Enable and start MariaDB service
	if ! systemctl is-enabled --quiet mariadb 2> /dev/null; then
		if ! run_cmd systemctl enable --quiet mariadb; then
			echo "Error: Failed to enable MariaDB service" >&2
			exit 1
		fi
	fi

	if ! systemctl is-active --quiet mariadb 2> /dev/null; then
		if ! run_cmd systemctl start mariadb; then
			echo "Error: Failed to start MariaDB service" >&2
			exit 1
		fi
	fi

	# Verify MariaDB is accepting connections
	echo "→ Waiting for MariaDB to accept connections..."
	local max_wait=30
	local waited=0
	while ! run_cmd mariadb-admin ping --silent 2> /dev/null; do
		if ((waited >= max_wait)); then
			echo "Error: MariaDB started but is not accepting connections" >&2
			exit 1
		fi
		sleep 1
		waited=$((waited + 1))
	done
}

#
# Security Configuration
# ----

#
# Secure MariaDB installation

secure_installation() {
	echo "→ Securing MariaDB installation..."

	# Set root password for mysql_native_password auth (for remote tools)
	# Socket authentication for local root access is preserved
	# Use heredoc to pass SQL via stdin (avoids exposing password in process listings)
	if ! run_cmd mariadb <<- EOSQL 2> /dev/null; then
		ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD('${ROOT_PASS}');
		FLUSH PRIVILEGES;
	EOSQL
		echo "Error: Failed to set root password" >&2
		exit 1
	fi

	# Use MYSQL_PWD to avoid exposing password in process listings for subsequent commands
	export MYSQL_PWD="${ROOT_PASS}"

	# Remove anonymous users
	run_cmd mariadb -u root -e "DELETE FROM mysql.user WHERE User='';" 2> /dev/null || true

	# Remove remote root login
	run_cmd mariadb -u root -e "DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');" 2> /dev/null || true

	# Remove test database
	run_cmd mariadb -u root -e "DROP DATABASE IF EXISTS test;" 2> /dev/null || true
	run_cmd mariadb -u root -e "DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';" 2> /dev/null || true

	# Flush privileges
	run_cmd mariadb -u root -e "FLUSH PRIVILEGES;" 2> /dev/null || true
}

#
# User Management
# ----

#
# Create deployer user

create_deployer_user() {
	# Check if user already exists (MYSQL_PWD already exported in secure_installation)
	local user_exists
	user_exists=$(run_cmd mariadb -u root -N -e "SELECT COUNT(*) FROM mysql.user WHERE User='${DEPLOYER_USER}' AND Host='localhost';" 2> /dev/null)

	if [[ $user_exists == "1" ]]; then
		echo "→ Deployer user already exists, skipping user creation..."
		return 0
	fi

	echo "→ Creating deployer user..."

	# Use heredoc to pass SQL via stdin (avoids exposing password in process listings)
	if ! run_cmd mariadb -u root <<- EOSQL 2> /dev/null; then
		CREATE USER '${DEPLOYER_USER}'@'localhost' IDENTIFIED BY '${DEPLOYER_PASS}';
	EOSQL
		echo "Error: Failed to create deployer user" >&2
		exit 1
	fi
}

#
# Database Management
# ----

#
# Create deployer database

create_deployer_database() {
	# Check if database already exists (MYSQL_PWD already exported in secure_installation)
	local db_exists
	db_exists=$(run_cmd mariadb -u root -N -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='${DEPLOYER_DATABASE}';" 2> /dev/null)

	if [[ $db_exists == "1" ]]; then
		echo "→ Deployer database already exists, skipping database creation..."
		# Still ensure grants are in place
		run_cmd mariadb -u root -e "GRANT ALL PRIVILEGES ON ${DEPLOYER_DATABASE}.* TO '${DEPLOYER_USER}'@'localhost'; FLUSH PRIVILEGES;" 2> /dev/null || true
		return 0
	fi

	echo "→ Creating deployer database..."

	if ! run_cmd mariadb -u root -e "CREATE DATABASE ${DEPLOYER_DATABASE} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2> /dev/null; then
		echo "Error: Failed to create deployer database" >&2
		exit 1
	fi

	# Grant privileges
	if ! run_cmd mariadb -u root -e "GRANT ALL PRIVILEGES ON ${DEPLOYER_DATABASE}.* TO '${DEPLOYER_USER}'@'localhost'; FLUSH PRIVILEGES;" 2> /dev/null; then
		echo "Error: Failed to grant privileges to deployer user" >&2
		exit 1
	fi
}

#
# Logging Configuration
# ----

#
# Configure logrotate for MariaDB logs

config_logrotate() {
	echo "→ Setting up MariaDB logrotate..."

	local logrotate_config="/etc/logrotate.d/mariadb-deployer"

	if ! run_cmd tee "$logrotate_config" > /dev/null <<- 'EOF'; then
		/var/log/mysql/*.log {
		    daily
		    rotate 5
		    maxage 30
		    missingok
		    notifempty
		    compress
		    delaycompress
		    copytruncate
		}
	EOF
		echo "Error: Failed to create MariaDB logrotate config" >&2
		exit 1
	fi
}

# ----
# Main Execution
# ----

main() {
	# Check for MySQL conflict FIRST (before any installation)
	check_mysql_conflict

	# Check if MariaDB is already installed - exit gracefully if so
	if systemctl is-active --quiet mariadb 2> /dev/null; then
		echo "→ MariaDB server is already installed and running"

		# Return success with marker indicating already installed
		if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
			status: success
			already_installed: true
		EOF
			echo "Error: Failed to write output file" >&2
			exit 1
		fi
		exit 0
	fi

	# Generate credentials for fresh install
	ROOT_PASS=$(openssl rand -base64 24)
	DEPLOYER_PASS=$(openssl rand -base64 24)

	# Execute installation tasks
	install_packages
	secure_installation
	create_deployer_user
	create_deployer_database
	config_logrotate

	# Write output YAML
	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		status: success
		root_pass: ${ROOT_PASS}
		deployer_user: ${DEPLOYER_USER}
		deployer_pass: ${DEPLOYER_PASS}
		deployer_database: ${DEPLOYER_DATABASE}
	EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"
