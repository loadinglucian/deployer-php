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
#

run_cmd() {
	if [[ $DEPLOYER_PERMS == 'root' ]]; then
		"$@"
	else
		sudo -n "$@"
	fi
}

#
# Execute command as deployer user with environment preservation
#
# Arguments:
#   $@ - Command and arguments to execute

run_as_deployer() {
	if [[ $DEPLOYER_PERMS == 'root' || $DEPLOYER_PERMS == 'sudo' ]]; then
		sudo -n -u deployer --preserve-env="$PRESERVE_ENV_VARS" "$@"
	else
		"$@"
	fi
}

# ----
# Error Handling
# ----

#
# Print error message and exit
#
# Arguments:
#   $1 - Error message to display

fail() {
	echo "Error: $1" >&2
	exit 1
}

# ----
# PHP Detection
# ----

#
# Detect default PHP version
#

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
#

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
			echo "Waiting for package manager lock to be released..."
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
#

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
				echo "Package manager locked, waiting ${wait_time}s before retry (attempt ${attempt}/${max_attempts})..."
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

# ----
# Listening Services Detection
# ----

#
# Get listening services as port:process pairs
#
# Output: Sorted, deduplicated lines in format "port:process"
# Uses ss command with netstat fallback
#

get_listening_services() {
	local port process

	if command -v ss > /dev/null 2>&1; then
		while read -r line; do
			[[ $line =~ ^State ]] && continue
			[[ ! $line =~ LISTEN ]] && continue

			if [[ $line =~ :([0-9]+)[[:space:]] ]]; then
				port="${BASH_REMATCH[1]}"
				if [[ $line =~ users:\(\(\"([^\"]+)\" ]]; then
					process="${BASH_REMATCH[1]}"
				else
					process="unknown"
				fi
				echo "${port}:${process}"
			fi
		done < <(run_cmd ss -tlnp 2> /dev/null) | sort -t: -k1 -n | uniq

	elif command -v netstat > /dev/null 2>&1; then
		while read -r proto recvq sendq local foreign state program; do
			[[ $state != "LISTEN" ]] && continue

			if [[ $local =~ :([0-9]+)$ ]]; then
				port="${BASH_REMATCH[1]}"
				if [[ $program =~ /(.+)$ ]]; then
					process="${BASH_REMATCH[1]}"
				else
					process="unknown"
				fi
				echo "${port}:${process}"
			fi
		done < <(run_cmd netstat -tlnp 2> /dev/null | tail -n +3) | sort -t: -k1 -n | uniq
	fi
}

# ----
# Shared Resources Management
# ----

#
# Link shared resources to release
#
# Iterates through all items in shared directory and creates symlinks in the release.
# Removes conflicting files/directories from the release before linking.
# Requires environment variables:
#   $SHARED_PATH - Path to shared directory
#   $RELEASE_PATH - Path to release directory
#

link_shared_resources() {
	if [[ ! -d $SHARED_PATH ]]; then
		return 0
	fi

	local shared_items=()
	mapfile -t shared_items < <(find "$SHARED_PATH" -mindepth 1 -maxdepth 1 -printf '%f\n') || true

	if ((${#shared_items[@]} == 0)); then
		return 0
	fi

	echo "→ Linking shared resources..."

	for item in "${shared_items[@]}"; do
		local shared_item="${SHARED_PATH}/${item}"
		local release_item="${RELEASE_PATH}/${item}"

		# Remove conflicting item from release if it exists
		if [[ -e $release_item ]]; then
			run_cmd rm -rf "$release_item" || fail "Failed to remove ${item} from release"
		fi

		# Create symlink
		run_as_deployer ln -sf "$shared_item" "$release_item" || fail "Failed to link shared ${item}"
	done
}
