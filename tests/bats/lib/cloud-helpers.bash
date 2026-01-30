#!/usr/bin/env bash

# ----
# Provider Test Configuration
# ----
# All values loaded from .env file (see .env.example)

# SSH key path shared (same key, different names per provider)
export CLOUD_TEST_KEY_PATH="${CLOUD_TEST_KEY_PATH:-${BATS_TEST_ROOT}/fixtures/keys/id_test.pub}"

# ----
# AWS Test Configuration
# ----
# Instance sizing - t3.small (2 vCPU, 2 GB) recommended for faster installs
# Minimum: t3.micro (2 vCPU burst, 1 GB)

export AWS_TEST_KEY_NAME="${AWS_TEST_KEY_NAME:-deployer-bats-aws}"
export AWS_TEST_SERVER_NAME="${AWS_TEST_SERVER_NAME:-deployer-bats-aws}"
export AWS_TEST_INSTANCE_TYPE="${AWS_TEST_INSTANCE_TYPE:-t3.small}"
export AWS_TEST_AMI="${AWS_TEST_AMI:-}"
export AWS_TEST_KEY_PAIR="${AWS_TEST_KEY_PAIR:-}"
export AWS_TEST_VPC="${AWS_TEST_VPC:-}"
export AWS_TEST_SUBNET="${AWS_TEST_SUBNET:-}"
export AWS_TEST_PRIVATE_KEY_PATH="${AWS_TEST_PRIVATE_KEY_PATH:-$HOME/.ssh/id_ed25519}"
export AWS_TEST_DISK_SIZE="${AWS_TEST_DISK_SIZE:-8}"

# AWS DNS/Site Test Configuration
export AWS_TEST_DOMAIN="${AWS_TEST_DOMAIN:-deployeraws.eu}"
export AWS_TEST_HOSTED_ZONE="${AWS_TEST_HOSTED_ZONE:-deployeraws.eu}"

# ----
# DigitalOcean Test Configuration
# ----
# Droplet sizing - s-2vcpu-2gb recommended for faster installs
# Minimum: s-1vcpu-1gb

export DO_TEST_KEY_NAME="${DO_TEST_KEY_NAME:-deployer-bats-do}"
export DO_TEST_SERVER_NAME="${DO_TEST_SERVER_NAME:-deployer-bats-do}"
export DO_TEST_SSH_KEY_ID="${DO_TEST_SSH_KEY_ID:-}"
export DO_TEST_PRIVATE_KEY_PATH="${DO_TEST_PRIVATE_KEY_PATH:-$HOME/.ssh/id_ed25519}"
export DO_TEST_REGION="${DO_TEST_REGION:-}"
export DO_TEST_SIZE="${DO_TEST_SIZE:-s-2vcpu-2gb}"
export DO_TEST_IMAGE="${DO_TEST_IMAGE:-}"
export DO_TEST_VPC_UUID="${DO_TEST_VPC_UUID:-default}"

# DigitalOcean DNS/Site Test Configuration
export DO_TEST_DOMAIN="${DO_TEST_DOMAIN:-deployerdo.eu}"

# ----
# Cloudflare Test Configuration
# ----
# DNS-only provider - uses AWS-provisioned server IP for record values

export CF_TEST_DOMAIN="${CF_TEST_DOMAIN:-deployercf.eu}"

# ----
# Shared Deployment Test Configuration
# ----

export CLOUD_TEST_PHP_VERSION="${CLOUD_TEST_PHP_VERSION:-8.4}"
export CLOUD_TEST_PHP_EXTENSIONS="${CLOUD_TEST_PHP_EXTENSIONS:-fpm,bcmath,curl,mbstring,xml,zip}"
export CLOUD_TEST_DEPLOY_REPO="${CLOUD_TEST_DEPLOY_REPO:-https://github.com/loadinglucian/deploy-me.git}"
export CLOUD_TEST_DEPLOY_BRANCH="${CLOUD_TEST_DEPLOY_BRANCH:-main}"
export CLOUD_TEST_APP_MESSAGE="${CLOUD_TEST_APP_MESSAGE:-DeployerPHP-BATS-Test-Success}"

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
	"$DEPLOYER_BIN" aws:key:delete \
		--key="$AWS_TEST_KEY_NAME" \
		--force \
		--yes 2> /dev/null || true
}

# Cleanup AWS provisioned test server (idempotent - ignores "not found")
aws_cleanup_test_server() {
	"$DEPLOYER_BIN" server:delete \
		--server="$AWS_TEST_SERVER_NAME" \
		--force \
		--yes \
		--destroy-cloud 2> /dev/null || true
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
	"$DEPLOYER_BIN" do:key:list 2> /dev/null \
		| LC_ALL=C tr -cd '[:print:]\n' \
		| grep "$key_name" \
		| grep -oE '[0-9]{7,8}' \
		| head -1
}

# Cleanup DO test key (idempotent - ignores "not found")
do_cleanup_test_key() {
	local key_id
	key_id=$(do_find_key_id_by_name "$DO_TEST_KEY_NAME")

	if [[ -n "$key_id" ]]; then
		"$DEPLOYER_BIN" do:key:delete \
			--key="$key_id" \
			--force \
			--yes 2> /dev/null || true
	fi
}

# Cleanup DO provisioned test server (idempotent - ignores "not found")
do_cleanup_test_server() {
	"$DEPLOYER_BIN" server:delete \
		--server="$DO_TEST_SERVER_NAME" \
		--force \
		--yes \
		--destroy-cloud 2> /dev/null || true
}

# ----
# Cloudflare Helpers
# ----

# Check if Cloudflare credentials are configured
cf_credentials_available() {
	[[ -n "${CLOUDFLARE_API_TOKEN:-}${CF_API_TOKEN:-}" ]]
}

# ----
# Shared Helpers
# ----

# Get server IP from inventory
# Usage: get_server_ip "server-name"
# Returns: IP address or empty string if not found
# Note: Parses text output since --format=json still includes banner
get_server_ip() {
	local server_name="$1"
	"$DEPLOYER_BIN" --inventory="$TEST_INVENTORY" server:info \
		--server="$server_name" 2> /dev/null \
		| grep -E '^▒ Host:' \
		| sed 's/.*Host:[[:space:]]*//'
}

# Cleanup test site (idempotent - ignores "not found")
# Usage: cleanup_test_site "example.com"
cleanup_test_site() {
	local domain="$1"
	"$DEPLOYER_BIN" --inventory="$TEST_INVENTORY" site:delete \
		--domain="$domain" \
		--force \
		--yes 2> /dev/null || true
}

# Wait for HTTP response with optional content verification
# Usage: wait_for_http "example.com" "expected-content" 180 "1.2.3.4"
# Args:
#   $1 - domain to check
#   $2 - expected content (optional, empty string to skip content check)
#   $3 - timeout in seconds (default: 180)
#   $4 - server IP (optional, bypasses DNS using curl --resolve)
wait_for_http() {
	local domain="$1"
	local expected="${2:-}"
	local timeout="${3:-180}"
	local server_ip="${4:-}"
	local interval=5
	local elapsed=0
	local last_response=""
	local last_http_code=""

	# Build curl command - use --resolve to bypass DNS if IP provided
	local curl_opts=(-sL --max-time 10)
	if [[ -n "$server_ip" ]]; then
		curl_opts+=(--resolve "${domain}:80:${server_ip}")
		echo "Using direct IP ${server_ip} (bypassing DNS)"
	fi

	while [[ $elapsed -lt $timeout ]]; do
		local response http_code
		# Get both response body and HTTP status code
		response=$(curl "${curl_opts[@]}" -w "\n__HTTP_CODE__:%{http_code}" "http://${domain}" 2> /dev/null || true)
		http_code="${response##*__HTTP_CODE__:}"
		response="${response%__HTTP_CODE__:*}"

		last_response="$response"
		last_http_code="$http_code"

		if [[ -n "$response" ]]; then
			if [[ -z "$expected" ]] || [[ "$response" == *"$expected"* ]]; then
				echo "HTTP response received from ${domain} (HTTP ${http_code})"
				[[ -n "$expected" ]] && echo "Found expected content: ${expected}"
				return 0
			fi
		fi

		sleep $interval
		elapsed=$((elapsed + interval))
	done

	echo "Timeout waiting for HTTP response from ${domain} after ${timeout}s"
	echo "Last HTTP code: ${last_http_code:-none}"
	[[ -n "$expected" ]] && echo "Expected content not found: ${expected}"
	if [[ -n "$last_response" ]]; then
		echo "Last response (first 500 chars):"
		echo "${last_response:0:500}"
	else
		echo "No response received"
	fi
	return 1
}
