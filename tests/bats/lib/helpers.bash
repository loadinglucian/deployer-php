#!/usr/bin/env bash

# ----
# Configuration
# ----

# Resolve paths relative to test file location
export BATS_TEST_ROOT="${BATS_TEST_DIRNAME:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
export PROJECT_ROOT="$(cd "${BATS_TEST_ROOT}/../.." && pwd)"
export DEPLOYER_BIN="${PROJECT_ROOT}/bin/deployer"
export TEST_INVENTORY="${BATS_TEST_ROOT}/fixtures/inventory/test-deployer.yml"
export TEST_KEY="${BATS_TEST_ROOT}/fixtures/keys/id_test"

# Distro configuration - set by run.sh via BATS_DISTRO environment variable
export BATS_DISTRO="${BATS_DISTRO:-ubuntu24}"

# Distro port mapping (must match run.sh and lima/*.yaml)
declare -A DISTRO_PORTS=(
	["ubuntu24"]="2222"
	["ubuntu25"]="2224"
	["debian12"]="2223"
	["debian13"]="2225"
)

# Test server connection - derived from BATS_DISTRO
export TEST_SERVER_HOST="127.0.0.1"
export TEST_SERVER_PORT="${DISTRO_PORTS[$BATS_DISTRO]}"
export TEST_SERVER_NAME="test-${BATS_DISTRO}"
export TEST_VM_NAME="deployer-test-${BATS_DISTRO}"
export TEST_SERVER_USER="root"

# ----
# Output Pattern Assertions
# ----

# Assert output contains success marker (checkmark after prefix)
# Usage: assert_success_output
assert_success_output() {
	if [[ ! "$output" =~ "✓" ]]; then
		echo "Expected success marker (✓) in output"
		echo "Actual output: $output"
		return 1
	fi
}

# Assert output contains error marker (X after prefix)
# Usage: assert_error_output
assert_error_output() {
	if [[ ! "$output" =~ "✗" ]]; then
		echo "Expected error marker (✗) in output"
		echo "Actual output: $output"
		return 1
	fi
}

# Assert output contains warning marker
# Usage: assert_warning_output
assert_warning_output() {
	if [[ ! "$output" =~ "!" ]]; then
		echo "Expected warning marker (!) in output"
		echo "Actual output: $output"
		return 1
	fi
}

# Assert output contains info marker
# Usage: assert_info_output
assert_info_output() {
	if [[ ! "$output" =~ "ℹ" ]]; then
		echo "Expected info marker (ℹ) in output"
		echo "Actual output: $output"
		return 1
	fi
}

# Assert output contains bullet item
# Usage: assert_bullet_output
assert_bullet_output() {
	if [[ ! "$output" =~ "•" ]]; then
		echo "Expected bullet marker (•) in output"
		echo "Actual output: $output"
		return 1
	fi
}

# Assert output contains command replay
# Usage: assert_command_replay "server:add"
assert_command_replay() {
	local command="$1"
	if [[ ! "$output" =~ "\$> ".*"deployer ${command}" ]]; then
		echo "Expected command replay for '${command}' in output"
		echo "Actual output: $output"
		return 1
	fi
}

# Assert output contains specific text
# Usage: assert_output_contains "Server added"
assert_output_contains() {
	local expected="$1"
	if [[ ! "$output" =~ "$expected" ]]; then
		echo "Expected output to contain: ${expected}"
		echo "Actual output: $output"
		return 1
	fi
}

# Assert output does NOT contain text
# Usage: assert_output_not_contains "Error"
assert_output_not_contains() {
	local unexpected="$1"
	if [[ "$output" =~ "$unexpected" ]]; then
		echo "Expected output NOT to contain: ${unexpected}"
		echo "Actual output: $output"
		return 1
	fi
}

# Assert exit status is success (0)
# Usage: assert_success
assert_success() {
	if [[ "$status" -ne 0 ]]; then
		echo "Expected exit status 0, got ${status}"
		echo "Output: $output"
		return 1
	fi
}

# Assert exit status is failure (non-zero)
# Usage: assert_failure
assert_failure() {
	if [[ "$status" -eq 0 ]]; then
		echo "Expected non-zero exit status, got 0"
		echo "Output: $output"
		return 1
	fi
}

# ----
# Test Execution Helpers
# ----

# Run deployer command with test inventory
# Usage: run_deployer server:info --server=test-server
run_deployer() {
	run "$DEPLOYER_BIN" --inventory="$TEST_INVENTORY" "$@"
}

# Run deployer command expecting success (exit code 0)
# Usage: run_deployer_success server:info --server=test-server
run_deployer_success() {
	run_deployer "$@"
	assert_success
}

# Run deployer command expecting failure (exit code non-zero)
# Usage: run_deployer_failure server:add --name=""
run_deployer_failure() {
	run_deployer "$@"
	assert_failure
}

# ----
# SSH Helpers
# ----

# Execute command on test server via SSH
# Usage: ssh_exec "whoami"
ssh_exec() {
	local cmd="$1"
	ssh -i "$TEST_KEY" \
		-o StrictHostKeyChecking=no \
		-o UserKnownHostsFile=/dev/null \
		-o LogLevel=ERROR \
		-p "$TEST_SERVER_PORT" \
		"${TEST_SERVER_USER}@${TEST_SERVER_HOST}" \
		"$cmd"
}

# Check if file exists on test server
# Usage: assert_remote_file_exists "/etc/nginx/nginx.conf"
assert_remote_file_exists() {
	local path="$1"
	if ! ssh_exec "test -f '$path'"; then
		echo "Expected remote file to exist: ${path}"
		return 1
	fi
}

# Check if directory exists on test server
# Usage: assert_remote_dir_exists "/home/deployer/sites"
assert_remote_dir_exists() {
	local path="$1"
	if ! ssh_exec "test -d '$path'"; then
		echo "Expected remote directory to exist: ${path}"
		return 1
	fi
}

# Check if remote file contains text
# Usage: assert_remote_file_contains "/etc/hosts" "localhost"
assert_remote_file_contains() {
	local path="$1"
	local expected="$2"
	if ! ssh_exec "grep -q '$expected' '$path'"; then
		echo "Expected remote file ${path} to contain: ${expected}"
		return 1
	fi
}

# ----
# Debug Helpers
# ----

# Print output for debugging (only when BATS_DEBUG=1)
# Usage: debug_output
debug_output() {
	if [[ "${BATS_DEBUG:-0}" == "1" ]]; then
		echo "# STATUS: $status" >&3
		echo "# OUTPUT:" >&3
		echo "$output" | sed 's/^/# /' >&3
	fi
}

# Print a debug message (only when BATS_DEBUG=1)
# Usage: debug "Processing step X"
debug() {
	if [[ "${BATS_DEBUG:-0}" == "1" ]]; then
		echo "# DEBUG: $*" >&3
	fi
}
