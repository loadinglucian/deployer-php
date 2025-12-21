#!/usr/bin/env bash

#
# PostgreSQL Installation
#
# Installs PostgreSQL server, configures postgres password, and creates deployer database/user.
#
# Output (fresh install):
#   status: success
#   postgres_pass: Ek3jF8mNpQ2rS5tV
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
POSTGRES_PASS=""
DEPLOYER_USER="deployer"
DEPLOYER_PASS=""
DEPLOYER_DATABASE="deployer"

# ----
# Installation Functions
# ----

#
# Package Installation
# ----

#
# Install PostgreSQL packages

install_packages() {
	echo "→ Installing PostgreSQL..."

	local packages=(postgresql postgresql-client)

	if ! apt_get_with_retry install -y "${packages[@]}" 2>&1; then
		echo "Error: Failed to install PostgreSQL packages" >&2
		exit 1
	fi

	# Enable and start PostgreSQL service
	if ! systemctl is-enabled --quiet postgresql 2> /dev/null; then
		if ! run_cmd systemctl enable --quiet postgresql; then
			echo "Error: Failed to enable PostgreSQL service" >&2
			exit 1
		fi
	fi

	if ! systemctl is-active --quiet postgresql 2> /dev/null; then
		if ! run_cmd systemctl start postgresql; then
			echo "Error: Failed to start PostgreSQL service" >&2
			exit 1
		fi
	fi

	# Verify PostgreSQL is accepting connections
	echo "→ Waiting for PostgreSQL to accept connections..."
	local max_wait=30
	local waited=0
	while ! run_cmd pg_isready -h localhost -q 2> /dev/null; do
		if ((waited >= max_wait)); then
			echo "Error: PostgreSQL started but is not accepting connections" >&2
			exit 1
		fi
		sleep 1
		waited=$((waited + 1))
	done
}

#
# Logging Configuration
# ----

#
# Configure PostgreSQL logging for predictable log path

configure_logging() {
	echo "→ Configuring PostgreSQL logging..."

	# Find the PostgreSQL config directory (version-specific)
	local pg_version
	pg_version=$(find /etc/postgresql/ -mindepth 1 -maxdepth 1 -type d -exec basename {} \; 2> /dev/null | head -1)

	if [[ -z $pg_version ]]; then
		echo "Error: Could not detect PostgreSQL version" >&2
		exit 1
	fi

	local pg_conf="/etc/postgresql/${pg_version}/main/postgresql.conf"

	if [[ ! -f $pg_conf ]]; then
		echo "Error: PostgreSQL config not found at ${pg_conf}" >&2
		exit 1
	fi

	# Configure logging to use a predictable filename
	# First, ensure log_destination and logging_collector are set
	if ! grep -q "^log_destination = 'stderr'" "$pg_conf" 2> /dev/null; then
		if ! run_cmd sed -i "s/^#*log_destination.*/log_destination = 'stderr'/" "$pg_conf"; then
			echo "Error: Failed to configure log_destination" >&2
			exit 1
		fi
	fi

	if ! grep -q "^logging_collector = on" "$pg_conf" 2> /dev/null; then
		if ! run_cmd sed -i "s/^#*logging_collector.*/logging_collector = on/" "$pg_conf"; then
			echo "Error: Failed to configure logging_collector" >&2
			exit 1
		fi
	fi

	if ! grep -q "^log_directory = '/var/log/postgresql'" "$pg_conf" 2> /dev/null; then
		if ! run_cmd sed -i "s|^#*log_directory.*|log_directory = '/var/log/postgresql'|" "$pg_conf"; then
			echo "Error: Failed to configure log_directory" >&2
			exit 1
		fi
	fi

	# Set a fixed log filename instead of the version-specific default
	if ! grep -q "^log_filename = 'postgresql.log'" "$pg_conf" 2> /dev/null; then
		if ! run_cmd sed -i "s/^#*log_filename.*/log_filename = 'postgresql.log'/" "$pg_conf"; then
			echo "Error: Failed to configure log_filename" >&2
			exit 1
		fi
	fi

	# Disable log rotation in PostgreSQL (we'll use logrotate)
	if ! grep -q "^log_rotation_age = 0" "$pg_conf" 2> /dev/null; then
		if ! run_cmd sed -i "s/^#*log_rotation_age.*/log_rotation_age = 0/" "$pg_conf"; then
			echo "Error: Failed to disable log_rotation_age" >&2
			exit 1
		fi
	fi

	# Ensure log directory exists and has correct ownership
	if ! run_cmd mkdir -p /var/log/postgresql; then
		echo "Error: Failed to create log directory" >&2
		exit 1
	fi

	if ! run_cmd chown postgres:postgres /var/log/postgresql; then
		echo "Error: Failed to set log directory ownership" >&2
		exit 1
	fi
}

#
# Security Configuration
# ----

#
# Set postgres user password

set_postgres_password() {
	echo "→ Setting postgres user password..."

	# Set password for postgres user using psql as postgres user
	if ! run_cmd su - postgres -c "psql -c \"ALTER USER postgres WITH PASSWORD '${POSTGRES_PASS}';\"" > /dev/null 2>&1; then
		echo "Error: Failed to set postgres password" >&2
		exit 1
	fi
}

#
# Configure pg_hba.conf for password authentication

configure_auth() {
	echo "→ Configuring PostgreSQL authentication..."

	# Find the PostgreSQL config directory (version-specific)
	local pg_version
	pg_version=$(find /etc/postgresql/ -mindepth 1 -maxdepth 1 -type d -exec basename {} \; 2> /dev/null | head -1)

	if [[ -z $pg_version ]]; then
		echo "Error: Could not detect PostgreSQL version" >&2
		exit 1
	fi

	local pg_hba="/etc/postgresql/${pg_version}/main/pg_hba.conf"

	if [[ ! -f $pg_hba ]]; then
		echo "Error: pg_hba.conf not found at ${pg_hba}" >&2
		exit 1
	fi

	# Check if we've already configured this
	if grep -q "# DEPLOYER-MANAGED" "$pg_hba" 2> /dev/null; then
		echo "→ Authentication already configured, skipping..."
		return 0
	fi

	# Backup original config
	run_cmd cp "$pg_hba" "${pg_hba}.bak"

	# Configure authentication:
	# - local connections use peer for postgres user
	# - local connections use scram-sha-256 for other users
	# - host connections use scram-sha-256
	if ! run_cmd tee "$pg_hba" > /dev/null <<- 'EOF'; then
		# DEPLOYER-MANAGED PostgreSQL Client Authentication Configuration
		# TYPE  DATABASE        USER            ADDRESS                 METHOD

		# Local connections
		local   all             postgres                                peer
		local   all             all                                     scram-sha-256

		# IPv4 local connections
		host    all             all             127.0.0.1/32            scram-sha-256

		# IPv6 local connections
		host    all             all             ::1/128                 scram-sha-256
	EOF
		echo "Error: Failed to configure pg_hba.conf" >&2
		exit 1
	fi
}

#
# User Management
# ----

#
# Create deployer user

create_deployer_user() {
	# Check if user already exists
	local user_exists
	user_exists=$(run_cmd su - postgres -c "psql -tAc \"SELECT 1 FROM pg_roles WHERE rolname='${DEPLOYER_USER}';\"" 2> /dev/null)

	if [[ $user_exists == "1" ]]; then
		echo "→ Deployer user already exists, skipping user creation..."
		return 0
	fi

	echo "→ Creating deployer user..."

	if ! run_cmd su - postgres -c "psql -c \"CREATE USER ${DEPLOYER_USER} WITH PASSWORD '${DEPLOYER_PASS}';\"" > /dev/null 2>&1; then
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
	# Check if database already exists
	local db_exists
	db_exists=$(run_cmd su - postgres -c "psql -tAc \"SELECT 1 FROM pg_database WHERE datname='${DEPLOYER_DATABASE}';\"" 2> /dev/null)

	if [[ $db_exists == "1" ]]; then
		echo "→ Deployer database already exists, skipping database creation..."
		# Ensure ownership is correct
		run_cmd su - postgres -c "psql -c \"ALTER DATABASE ${DEPLOYER_DATABASE} OWNER TO ${DEPLOYER_USER};\"" > /dev/null 2>&1 || true
		return 0
	fi

	echo "→ Creating deployer database..."

	if ! run_cmd su - postgres -c "psql -c \"CREATE DATABASE ${DEPLOYER_DATABASE} OWNER ${DEPLOYER_USER} ENCODING 'UTF8' LC_COLLATE 'en_US.UTF-8' LC_CTYPE 'en_US.UTF-8' TEMPLATE template0;\"" > /dev/null 2>&1; then
		echo "Error: Failed to create deployer database" >&2
		exit 1
	fi

	# Grant all privileges
	if ! run_cmd su - postgres -c "psql -c \"GRANT ALL PRIVILEGES ON DATABASE ${DEPLOYER_DATABASE} TO ${DEPLOYER_USER};\"" > /dev/null 2>&1; then
		echo "Error: Failed to grant privileges to deployer user" >&2
		exit 1
	fi
}

#
# Logrotate Configuration
# ----

#
# Configure logrotate for PostgreSQL logs

config_logrotate() {
	echo "→ Setting up PostgreSQL logrotate..."

	local logrotate_config="/etc/logrotate.d/postgresql-deployer"

	if ! run_cmd tee "$logrotate_config" > /dev/null <<- 'EOF'; then
		/var/log/postgresql/postgresql.log {
		    daily
		    rotate 5
		    maxage 30
		    missingok
		    notifempty
		    compress
		    delaycompress
		    copytruncate
		    su postgres postgres
		}
	EOF
		echo "Error: Failed to create PostgreSQL logrotate config" >&2
		exit 1
	fi
}

# ----
# Main Execution
# ----

main() {
	# Check if PostgreSQL is already installed - exit gracefully if so
	if systemctl is-active --quiet postgresql 2> /dev/null; then
		echo "→ PostgreSQL server is already installed and running"

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
	POSTGRES_PASS=$(openssl rand -base64 24)
	DEPLOYER_PASS=$(openssl rand -base64 24)

	# Execute installation tasks
	install_packages
	configure_logging
	set_postgres_password
	configure_auth
	create_deployer_user
	create_deployer_database
	config_logrotate

	# Restart PostgreSQL to apply logging and auth changes
	echo "→ Restarting PostgreSQL to apply configuration..."
	if ! run_cmd systemctl restart postgresql; then
		echo "Error: Failed to restart PostgreSQL service" >&2
		exit 1
	fi

	# Wait for service to be ready after restart
	local max_wait=30
	local waited=0
	while ! run_cmd pg_isready -h localhost -q 2> /dev/null; do
		if ((waited >= max_wait)); then
			echo "Error: PostgreSQL not accepting connections after restart" >&2
			exit 1
		fi
		sleep 1
		waited=$((waited + 1))
	done

	# Write output YAML
	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		status: success
		postgres_pass: ${POSTGRES_PASS}
		deployer_user: ${DEPLOYER_USER}
		deployer_pass: ${DEPLOYER_PASS}
		deployer_database: ${DEPLOYER_DATABASE}
	EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"
