#!/usr/bin/env bash

# ----
# Provider Test Configuration
# ----
# All values loaded from .env file (see .env.example)

export PRO_TEST_KEY_NAME="${PRO_TEST_KEY_NAME:-deployer-bats-test}"
export PRO_TEST_KEY_PATH="${PRO_TEST_KEY_PATH:-${BATS_TEST_ROOT}/fixtures/keys/id_test.pub}"
export PRO_TEST_SERVER_NAME="${PRO_TEST_SERVER_NAME:-deployer-bats-provision}"

# ----
# AWS Provision Test Configuration
# ----

export AWS_TEST_INSTANCE_TYPE="${AWS_TEST_INSTANCE_TYPE:-}"
export AWS_TEST_AMI="${AWS_TEST_AMI:-}"
export AWS_TEST_KEY_PAIR="${AWS_TEST_KEY_PAIR:-}"
export AWS_TEST_VPC="${AWS_TEST_VPC:-}"
export AWS_TEST_SUBNET="${AWS_TEST_SUBNET:-}"
export AWS_TEST_PRIVATE_KEY_PATH="${AWS_TEST_PRIVATE_KEY_PATH:-$HOME/.ssh/id_ed25519}"
export AWS_TEST_DISK_SIZE="${AWS_TEST_DISK_SIZE:-8}"

# ----
# DigitalOcean Provision Test Configuration
# ----

export DO_TEST_SSH_KEY_ID="${DO_TEST_SSH_KEY_ID:-}"
export DO_TEST_PRIVATE_KEY_PATH="${DO_TEST_PRIVATE_KEY_PATH:-$HOME/.ssh/id_ed25519}"
export DO_TEST_REGION="${DO_TEST_REGION:-}"
export DO_TEST_SIZE="${DO_TEST_SIZE:-}"
export DO_TEST_IMAGE="${DO_TEST_IMAGE:-}"
export DO_TEST_VPC_UUID="${DO_TEST_VPC_UUID:-default}"

# ----
# AWS Helpers
# ----

# Check if AWS credentials are configured
aws_credentials_available() {
	[[ -n "${AWS_ACCESS_KEY_ID:-}" ]] \
		&& [[ -n "${AWS_SECRET_ACCESS_KEY:-}" ]] \
		&& [[ -n "${AWS_DEFAULT_REGION:-}${AWS_REGION:-}" ]]
}

# Check if AWS provision test configuration is complete
aws_provision_config_available() {
	aws_credentials_available \
		&& [[ -n "$AWS_TEST_INSTANCE_TYPE" ]] \
		&& [[ -n "$AWS_TEST_AMI" ]] \
		&& [[ -n "$AWS_TEST_KEY_PAIR" ]] \
		&& [[ -n "$AWS_TEST_VPC" ]] \
		&& [[ -n "$AWS_TEST_SUBNET" ]] \
		&& [[ -f "$AWS_TEST_PRIVATE_KEY_PATH" ]]
}

# Cleanup AWS test key (idempotent - ignores "not found")
aws_cleanup_test_key() {
	"$DEPLOYER_BIN" pro:aws:key:delete \
		--key="$PRO_TEST_KEY_NAME" \
		--force \
		--yes 2> /dev/null || true
}

# Cleanup AWS provisioned test server (idempotent - ignores "not found")
aws_cleanup_test_server() {
	"$DEPLOYER_BIN" server:delete \
		--server="$PRO_TEST_SERVER_NAME" \
		--force \
		--yes 2> /dev/null || true
}

# ----
# DigitalOcean Helpers
# ----

# Check if DO credentials are configured
do_credentials_available() {
	[[ -n "${DIGITALOCEAN_API_TOKEN:-}${DO_API_TOKEN:-}" ]]
}

# Check if DO provision test configuration is complete
do_provision_config_available() {
	do_credentials_available \
		&& [[ -n "$DO_TEST_SSH_KEY_ID" ]] \
		&& [[ -n "$DO_TEST_REGION" ]] \
		&& [[ -n "$DO_TEST_SIZE" ]] \
		&& [[ -n "$DO_TEST_IMAGE" ]] \
		&& [[ -f "$DO_TEST_PRIVATE_KEY_PATH" ]]
}

# Extract key ID from key:add output
# Input: "Public SSH key uploaded successfully (ID: 12345)"
# Returns: 12345
do_extract_key_id_from_output() {
	echo "$1" | grep -oE 'ID: [0-9]+' | grep -oE '[0-9]+'
}

# Find DO key ID by name from key:list output
# Usage: do_find_key_id_by_name "deployer-bats-test"
# Returns: Key ID or empty string if not found
# Output format: "▒ 52905304: deployer-bats-test (fc:e9:cc:0a:00:7...)"
# Note: Must strip ANSI/control codes and match 8-digit IDs (not short numbers in escapes)
do_find_key_id_by_name() {
	local key_name="$1"
	"$DEPLOYER_BIN" pro:do:key:list 2> /dev/null \
		| LC_ALL=C tr -cd '[:print:]\n' \
		| grep "$key_name" \
		| grep -oE '[0-9]{7,8}' \
		| head -1
}

# Cleanup DO test key (idempotent - ignores "not found")
do_cleanup_test_key() {
	local key_id
	key_id=$(do_find_key_id_by_name "$PRO_TEST_KEY_NAME")

	if [[ -n "$key_id" ]]; then
		"$DEPLOYER_BIN" pro:do:key:delete \
			--key="$key_id" \
			--force \
			--yes 2> /dev/null || true
	fi
}

# Cleanup DO provisioned test server (idempotent - ignores "not found")
do_cleanup_test_server() {
	"$DEPLOYER_BIN" server:delete \
		--server="$PRO_TEST_SERVER_NAME" \
		--force \
		--yes 2> /dev/null || true
}
