#!/usr/bin/env bash

#
# Shared Playbook Helpers
# ----
# Common bash functions used across multiple playbooks.
# Source this file at the top of playbooks that need these functions:
#   source "$(dirname "$0")/helpers.sh"
#

# ----
# Permission Management
# ----

#
# Execute command with appropriate permissions

run_cmd() {
	if [[ $DEPLOYER_PERMS == 'root' ]]; then
		"$@"
	else
		sudo -n "$@"
	fi
}

# ----
# PHP Detection
# ----

#
# Detect default PHP version

detect_php_default() {
	local default_version

	# Try update-alternatives first
	if command -v update-alternatives > /dev/null 2>&1; then
		default_version=$(update-alternatives --query php 2> /dev/null | grep '^Value:' | awk '{print $2}')
		if [[ -n $default_version && $default_version =~ php([0-9]+\.[0-9]+)$ ]]; then
			echo "${BASH_REMATCH[1]}"
			return
		fi
	fi

	# Fallback: check /usr/bin/php directly
	if [[ -x /usr/bin/php ]]; then
		default_version=$(/usr/bin/php -v 2> /dev/null | head -n1 | grep -oP 'PHP \K[0-9]+\.[0-9]+')
		if [[ -n $default_version ]]; then
			echo "$default_version"
			return
		fi
	fi
}

# ----
# Package Management
# ----

#
# Wait for dpkg lock to be released

wait_for_dpkg_lock() {
	local max_wait=60
	local waited=0
	local lock_found=false

	# Check multiple times to catch the lock even in race conditions
	while ((waited < max_wait)); do
		# Try to acquire the lock by checking if we can open it
		if fuser /var/lib/dpkg/lock-frontend > /dev/null 2>&1 \
			|| fuser /var/lib/dpkg/lock > /dev/null 2>&1 \
			|| fuser /var/lib/apt/lists/lock > /dev/null 2>&1; then
			lock_found=true
			echo "✓ Waiting for package manager lock to be released..."
			sleep 2
			waited=$((waited + 2))
		else
			# Lock not held, but wait a bit to ensure it's really released
			if [[ $lock_found == true ]]; then
				# Was locked before, give it extra time
				sleep 2
			else
				# Never saw lock, just a small delay
				sleep 1
			fi
			return 0
		fi
	done

	echo "Error: Timeout waiting for dpkg lock to be released" >&2
	return 1
}

#
# apt-get with retry

apt_get_with_retry() {
	local max_attempts=5
	local attempt=1
	local wait_time=10
	local output

	while ((attempt <= max_attempts)); do
		# Capture output to check for lock errors
		output=$(run_cmd apt-get "$@" 2>&1)
		local exit_code=$?

		if ((exit_code == 0)); then
			[[ -n $output ]] && echo "$output"
			return 0
		fi

		# Only retry on lock-related errors
		if echo "$output" | grep -qE 'Could not get lock|dpkg.*lock|Unable to acquire'; then
			if ((attempt < max_attempts)); then
				echo "✓ Package manager locked, waiting ${wait_time}s before retry (attempt ${attempt}/${max_attempts})..."
				sleep "$wait_time"
				wait_time=$((wait_time + 5))
				attempt=$((attempt + 1))
				wait_for_dpkg_lock || true
			else
				echo "$output" >&2
				return "$exit_code"
			fi
		else
			# Non-lock error, fail immediately
			echo "$output" >&2
			return "$exit_code"
		fi
	done

	return 1
}
