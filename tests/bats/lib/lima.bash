#!/usr/bin/env bash

# ----
# Lima VM Lifecycle Management (BATS Context)
# ----
# BATS-friendly wrappers around core Lima functions.
# Uses $BATS_DISTRO as the default distro when not specified.

# Source shared core functions
# shellcheck source=lima-core.bash
source "$(dirname "${BASH_SOURCE[0]}")/lima-core.bash"

#
# Get Lima instance name for current distro
#
# Arguments:
#   $1 - distro name (optional, defaults to $BATS_DISTRO)

bats_lima_instance_name() {
	local distro="${1:-$BATS_DISTRO}"
	lima_instance_name "$distro"
}

#
# Check if Lima instance is running for current distro
#
# Arguments:
#   $1 - distro name (optional, defaults to $BATS_DISTRO)

bats_lima_is_running() {
	local distro="${1:-$BATS_DISTRO}"
	local instance
	instance="$(lima_instance_name "$distro")"
	lima_is_running "$instance"
}

#
# Check if Lima instance exists for current distro
#
# Arguments:
#   $1 - distro name (optional, defaults to $BATS_DISTRO)

bats_lima_exists() {
	local distro="${1:-$BATS_DISTRO}"
	local instance
	instance="$(lima_instance_name "$distro")"
	lima_exists "$instance"
}

#
# Start Lima instance for current distro
#
# Arguments:
#   $1 - distro name (optional, defaults to $BATS_DISTRO)

lima_start() {
	local distro="${1:-$BATS_DISTRO}"
	local instance
	instance="$(lima_instance_name "$distro")"
	local config="${BATS_TEST_ROOT}/lima/${distro}.yaml"

	if lima_is_running "$instance"; then
		return 0
	fi

	if lima_exists "$instance"; then
		limactl start "$instance"
	else
		limactl start --name="$instance" "$config"
	fi
}

#
# Stop Lima instance for current distro
#
# Arguments:
#   $1 - distro name (optional, defaults to $BATS_DISTRO)

lima_stop() {
	local distro="${1:-$BATS_DISTRO}"
	local instance
	instance="$(lima_instance_name "$distro")"
	if lima_exists "$instance"; then
		limactl stop "$instance" 2> /dev/null || true
	fi
}

#
# Reset Lima instance to clean state (delete and recreate)
#
# Arguments:
#   $1 - distro name (optional, defaults to $BATS_DISTRO)

lima_reset() {
	local distro="${1:-$BATS_DISTRO}"
	local instance
	instance="$(lima_instance_name "$distro")"

	if lima_exists "$instance"; then
		limactl stop "$instance" 2> /dev/null || true
		limactl delete "$instance" --force 2> /dev/null || true
	fi

	lima_start "$distro"
}

#
# Wait for SSH to be ready
#
# Arguments:
#   $1 - max attempts (optional, defaults to 60)

lima_wait_ssh() {
	local max_attempts="${1:-60}"
	local attempt=0

	while [[ $attempt -lt $max_attempts ]]; do
		if ssh -i "$TEST_KEY" \
			-o StrictHostKeyChecking=no \
			-o UserKnownHostsFile=/dev/null \
			-o ConnectTimeout=2 \
			-o LogLevel=ERROR \
			-p "$TEST_SERVER_PORT" \
			"${TEST_SERVER_USER}@${TEST_SERVER_HOST}" \
			"echo ok" > /dev/null 2>&1; then
			return 0
		fi
		((attempt++))
		sleep 1
	done

	return 1
}

#
# Clean VM state (faster than full reset)
# Cleans up deployer-created artifacts without restarting VM

lima_clean() {
	# Remove deployer-created files and directories
	ssh_exec "rm -rf /home/deployer/sites/* 2>/dev/null || true"

	# Stop and clean up any installed services
	ssh_exec "systemctl stop nginx 2>/dev/null || true"
	ssh_exec "rm -rf /etc/nginx/sites-enabled/* 2>/dev/null || true"

	# Clean up supervisor programs
	ssh_exec "rm -rf /etc/supervisor/conf.d/deployer-* 2>/dev/null || true"
	ssh_exec "supervisorctl reread 2>/dev/null || true"

	# Clean up cron jobs
	ssh_exec "crontab -r 2>/dev/null || true"
}

#
# Execute a command inside the VM via limactl shell
#
# Arguments:
#   $1 - command to execute

lima_exec() {
	local distro="${BATS_DISTRO}"
	local instance
	instance="$(lima_instance_name "$distro")"
	limactl shell "$instance" -- sudo bash -c "$1"
}

#
# Get VM logs (journalctl)
#
# Arguments:
#   $1 - number of lines (optional, defaults to 50)

lima_logs() {
	local lines="${1:-50}"
	ssh_exec "journalctl -n ${lines} --no-pager"
}
