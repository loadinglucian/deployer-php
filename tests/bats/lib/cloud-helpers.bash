#!/usr/bin/env bash

# ----
# Provider Test Configuration
# ----
# All values loaded from .env file (see .env.example)

# SSH key path shared (same key, different names per provider)
export CLOUD_TEST_KEY_PATH="${CLOUD_TEST_KEY_PATH:-${BATS_TEST_ROOT}/fixtures/keys/id_test.pub}"

# ----
# Run-Scoped Resource Isolation
# ----
# Set by bats.sh before invoking bats. Fallback for direct bats invocation.

export BATS_RUN_SUFFIX="${BATS_RUN_SUFFIX:-unknown}"

# ----
# AWS Test Configuration
# ----
# Instance sizing and disk configuration

export AWS_TEST_KEY_NAME="${AWS_TEST_KEY_NAME:-deployer-bats-aws-${BATS_RUN_SUFFIX}}"
export AWS_TEST_SERVER_NAME="${AWS_TEST_SERVER_NAME:-deployer-bats-aws-${BATS_RUN_SUFFIX}}"
export AWS_TEST_INSTANCE_TYPE="${AWS_TEST_INSTANCE_TYPE:-}"
export AWS_TEST_IMAGE="${AWS_TEST_IMAGE:-}"
export AWS_TEST_KEY_PAIR="${AWS_TEST_KEY_PAIR:-}"
export AWS_TEST_VPC="${AWS_TEST_VPC:-}"
export AWS_TEST_SUBNET="${AWS_TEST_SUBNET:-}"
export AWS_TEST_PRIVATE_KEY_PATH="${AWS_TEST_PRIVATE_KEY_PATH:-$HOME/.ssh/id_ed25519}"
export AWS_TEST_DISK_SIZE="${AWS_TEST_DISK_SIZE:-}"

# AWS DNS/Site Test Configuration
export AWS_TEST_DOMAIN="${AWS_TEST_DOMAIN:-deployeraws.eu}"
export AWS_TEST_HOSTED_ZONE="${AWS_TEST_HOSTED_ZONE:-deployeraws.eu}"
export AWS_TEST_DNS_ROOT="r${BATS_RUN_SUFFIX}"
export AWS_TEST_DNS_WWW="www-r${BATS_RUN_SUFFIX}"

# ----
# DigitalOcean Test Configuration
# ----
# Droplet sizing and VPC configuration

export DO_TEST_KEY_NAME="${DO_TEST_KEY_NAME:-deployer-bats-do-${BATS_RUN_SUFFIX}}"
export DO_TEST_SERVER_NAME="${DO_TEST_SERVER_NAME:-deployer-bats-do-${BATS_RUN_SUFFIX}}"
export DO_TEST_SSH_KEY_ID="${DO_TEST_SSH_KEY_ID:-}"
export DO_TEST_PRIVATE_KEY_PATH="${DO_TEST_PRIVATE_KEY_PATH:-$HOME/.ssh/id_ed25519}"
export DO_TEST_REGION="${DO_TEST_REGION:-}"
export DO_TEST_SIZE="${DO_TEST_SIZE:-}"
export DO_TEST_IMAGE="${DO_TEST_IMAGE:-}"
export DO_TEST_VPC_UUID="${DO_TEST_VPC_UUID:-}"

# DigitalOcean DNS/Site Test Configuration
export DO_TEST_DOMAIN="${DO_TEST_DOMAIN:-deployerdo.eu}"
export DO_TEST_DNS_ROOT="r${BATS_RUN_SUFFIX}"
export DO_TEST_DNS_WWW="www-r${BATS_RUN_SUFFIX}"

# ----
# Cloudflare Test Configuration
# ----
# DNS-only provider - uses AWS-provisioned server IP for record values

export CF_TEST_DOMAIN="${CF_TEST_DOMAIN:-deployercf.eu}"
export CF_TEST_DNS_ROOT="r${BATS_RUN_SUFFIX}"
export CF_TEST_DNS_WWW="www-r${BATS_RUN_SUFFIX}"

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
		&& [[ -n "${AWS_REGION:-}" ]]
}

# Check if AWS provision test configuration is complete
aws_provision_config_available() {
	aws_credentials_available \
		&& [[ -n "$AWS_TEST_INSTANCE_TYPE" ]] \
		&& [[ -n "$AWS_TEST_IMAGE" ]] \
		&& [[ -n "$AWS_TEST_KEY_PAIR" ]] \
		&& [[ -n "$AWS_TEST_VPC" ]] \
		&& [[ -n "$AWS_TEST_SUBNET" ]] \
		&& [[ -n "$AWS_TEST_DISK_SIZE" ]] \
		&& [[ -f "$AWS_TEST_PRIVATE_KEY_PATH" ]]
}

# ----
# Requirement Guards (fail with diagnostics when config is missing)
# ----

# Require AWS credentials or fail with diagnostics
require_aws_credentials() {
	if aws_credentials_available; then return 0; fi
	echo "AWS credentials not configured"
	[[ -z "${AWS_ACCESS_KEY_ID:-}" ]] && echo "  Missing: AWS_ACCESS_KEY_ID"
	[[ -z "${AWS_SECRET_ACCESS_KEY:-}" ]] && echo "  Missing: AWS_SECRET_ACCESS_KEY"
	[[ -z "${AWS_REGION:-}" ]] && echo "  Missing: AWS_REGION"
	return 1
}

# Require full AWS provisioning config or fail with diagnostics
require_aws_provision_config() {
	if aws_provision_config_available; then return 0; fi
	echo "AWS provisioning configuration incomplete"
	[[ -z "${AWS_ACCESS_KEY_ID:-}" ]] && echo "  Missing: AWS_ACCESS_KEY_ID"
	[[ -z "${AWS_SECRET_ACCESS_KEY:-}" ]] && echo "  Missing: AWS_SECRET_ACCESS_KEY"
	[[ -z "${AWS_REGION:-}" ]] && echo "  Missing: AWS_REGION"
	[[ -z "$AWS_TEST_INSTANCE_TYPE" ]] && echo "  Missing: AWS_TEST_INSTANCE_TYPE"
	[[ -z "$AWS_TEST_IMAGE" ]] && echo "  Missing: AWS_TEST_IMAGE"
	[[ -z "$AWS_TEST_KEY_PAIR" ]] && echo "  Missing: AWS_TEST_KEY_PAIR"
	[[ -z "$AWS_TEST_VPC" ]] && echo "  Missing: AWS_TEST_VPC"
	[[ -z "$AWS_TEST_SUBNET" ]] && echo "  Missing: AWS_TEST_SUBNET"
	[[ -z "$AWS_TEST_DISK_SIZE" ]] && echo "  Missing: AWS_TEST_DISK_SIZE"
	[[ ! -f "$AWS_TEST_PRIVATE_KEY_PATH" ]] && echo "  Missing: SSH key at $AWS_TEST_PRIVATE_KEY_PATH"
	return 1
}

# Require Cloudflare credentials or fail with diagnostics
require_cf_credentials() {
	if cf_credentials_available; then return 0; fi
	echo "Cloudflare credentials not configured"
	[[ -z "${CLOUDFLARE_API_TOKEN:-}" ]] && [[ -z "${CF_API_TOKEN:-}" ]] && echo "  Missing: CLOUDFLARE_API_TOKEN or CF_API_TOKEN"
	return 1
}

# Require DO credentials or fail with diagnostics
require_do_credentials() {
	if do_credentials_available; then return 0; fi
	echo "DigitalOcean credentials not configured"
	[[ -z "${DIGITALOCEAN_API_TOKEN:-}" ]] && [[ -z "${DO_API_TOKEN:-}" ]] && echo "  Missing: DIGITALOCEAN_API_TOKEN or DO_API_TOKEN"
	return 1
}

# Require full DO provisioning config or fail with diagnostics
require_do_provision_config() {
	if do_provision_config_available; then return 0; fi
	echo "DigitalOcean provisioning configuration incomplete"
	[[ -z "${DIGITALOCEAN_API_TOKEN:-}" ]] && [[ -z "${DO_API_TOKEN:-}" ]] && echo "  Missing: DIGITALOCEAN_API_TOKEN or DO_API_TOKEN"
	[[ -z "$DO_TEST_SSH_KEY_ID" ]] && echo "  Missing: DO_TEST_SSH_KEY_ID"
	[[ -z "$DO_TEST_REGION" ]] && echo "  Missing: DO_TEST_REGION"
	[[ -z "$DO_TEST_SIZE" ]] && echo "  Missing: DO_TEST_SIZE"
	[[ -z "$DO_TEST_IMAGE" ]] && echo "  Missing: DO_TEST_IMAGE"
	[[ -z "$DO_TEST_VPC_UUID" ]] && echo "  Missing: DO_TEST_VPC_UUID"
	[[ ! -f "$DO_TEST_PRIVATE_KEY_PATH" ]] && echo "  Missing: SSH key at $DO_TEST_PRIVATE_KEY_PATH"
	return 1
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
	"$DEPLOYER_BIN" --inventory="$TEST_INVENTORY" server:delete \
		--server="$AWS_TEST_SERVER_NAME" \
		--force \
		--yes \
		--destroy-cloud 2> /dev/null || true
}

# Cleanup AWS test DNS records (idempotent - ignores "not found")
aws_cleanup_test_dns() {
	"$DEPLOYER_BIN" aws:dns:delete \
		--zone="$AWS_TEST_HOSTED_ZONE" \
		--type="A" \
		--name="$AWS_TEST_DNS_ROOT" \
		--force \
		--yes 2> /dev/null || true

	"$DEPLOYER_BIN" aws:dns:delete \
		--zone="$AWS_TEST_HOSTED_ZONE" \
		--type="A" \
		--name="$AWS_TEST_DNS_WWW" \
		--force \
		--yes 2> /dev/null || true
}

# Cleanup AWS test DNS records via raw AWS CLI (safety net)
aws_cleanup_test_dns_raw() {
	command -v aws &> /dev/null || return 0

	local zone_id name record_json
	zone_id=$(aws route53 list-hosted-zones-by-name \
		--dns-name "$AWS_TEST_HOSTED_ZONE" \
		--query "HostedZones[?Name=='${AWS_TEST_HOSTED_ZONE}.'].Id" \
		--output text 2> /dev/null || true)
	[[ -n "$zone_id" ]] || return 0

	for name in "$AWS_TEST_DNS_ROOT" "$AWS_TEST_DNS_WWW"; do
		local fqdn="${name}.${AWS_TEST_HOSTED_ZONE}."
		record_json=$(aws route53 list-resource-record-sets \
			--hosted-zone-id "$zone_id" \
			--query "ResourceRecordSets[?Name=='${fqdn}' && Type=='A']|[0]" \
			--output json 2> /dev/null || true)
		[[ -n "$record_json" && "$record_json" != "null" ]] || continue

		aws route53 change-resource-record-sets \
			--hosted-zone-id "$zone_id" \
			--change-batch "{\"Changes\":[{\"Action\":\"DELETE\",\"ResourceRecordSet\":${record_json}}]}" \
			2> /dev/null || true
	done
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
		&& [[ -n "$DO_TEST_VPC_UUID" ]] \
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
	"$DEPLOYER_BIN" --no-ansi do:key:list 2> /dev/null \
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
	"$DEPLOYER_BIN" --inventory="$TEST_INVENTORY" server:delete \
		--server="$DO_TEST_SERVER_NAME" \
		--force \
		--yes \
		--destroy-cloud 2> /dev/null || true
}

# Cleanup DO test DNS records (idempotent - ignores "not found")
do_cleanup_test_dns() {
	"$DEPLOYER_BIN" do:dns:delete \
		--zone="$DO_TEST_DOMAIN" \
		--type="A" \
		--name="$DO_TEST_DNS_ROOT" \
		--force \
		--yes 2> /dev/null || true

	"$DEPLOYER_BIN" do:dns:delete \
		--zone="$DO_TEST_DOMAIN" \
		--type="A" \
		--name="$DO_TEST_DNS_WWW" \
		--force \
		--yes 2> /dev/null || true
}

# Cleanup DO test DNS records via raw DO API (safety net)
do_cleanup_test_dns_raw() {
	local token="${DO_API_TOKEN:-${DIGITALOCEAN_API_TOKEN:-}}"
	[[ -n "$token" ]] || return 0

	local domain="$DO_TEST_DOMAIN"

	for name in "$DO_TEST_DNS_ROOT" "$DO_TEST_DNS_WWW"; do
		local record_id
		record_id=$(curl -s -H "Authorization: Bearer ${token}" \
			"https://api.digitalocean.com/v2/domains/${domain}/records?type=A&name=${name}.${domain}" 2> /dev/null \
			| jq -r '.domain_records[0].id // empty' 2> /dev/null || true)
		[[ -n "$record_id" ]] || continue

		curl -s -X DELETE -H "Authorization: Bearer ${token}" \
			"https://api.digitalocean.com/v2/domains/${domain}/records/${record_id}" \
			2> /dev/null || true
	done
}

# ----
# Cloudflare Helpers
# ----

# Check if Cloudflare credentials are configured
cf_credentials_available() {
	[[ -n "${CLOUDFLARE_API_TOKEN:-}${CF_API_TOKEN:-}" ]]
}

# Cleanup Cloudflare test DNS records (idempotent - ignores "not found")
cf_cleanup_test_dns() {
	"$DEPLOYER_BIN" cf:dns:delete \
		--zone="$CF_TEST_DOMAIN" \
		--type="A" \
		--name="$CF_TEST_DNS_ROOT" \
		--force \
		--yes 2> /dev/null || true

	"$DEPLOYER_BIN" cf:dns:delete \
		--zone="$CF_TEST_DOMAIN" \
		--type="A" \
		--name="$CF_TEST_DNS_WWW" \
		--force \
		--yes 2> /dev/null || true
}

# Cleanup Cloudflare test DNS records via raw CF API (safety net)
cf_cleanup_test_dns_raw() {
	local token="${CF_API_TOKEN:-${CLOUDFLARE_API_TOKEN:-}}"
	[[ -n "$token" ]] || return 0

	local domain="$CF_TEST_DOMAIN"

	# Resolve zone ID
	local zone_id
	zone_id=$(curl -s -H "Authorization: Bearer ${token}" \
		"https://api.cloudflare.com/client/v4/zones?name=${domain}" 2> /dev/null \
		| jq -r '.result[0].id // empty' 2> /dev/null || true)
	[[ -n "$zone_id" ]] || return 0

	for name in "$CF_TEST_DNS_ROOT" "$CF_TEST_DNS_WWW"; do
		local fqdn="${name}.${domain}"
		local record_id
		record_id=$(curl -s -H "Authorization: Bearer ${token}" \
			"https://api.cloudflare.com/client/v4/zones/${zone_id}/dns_records?type=A&name=${fqdn}" 2> /dev/null \
			| jq -r '.result[0].id // empty' 2> /dev/null || true)
		[[ -n "$record_id" ]] || continue

		curl -s -X DELETE -H "Authorization: Bearer ${token}" \
			"https://api.cloudflare.com/client/v4/zones/${zone_id}/dns_records/${record_id}" \
			2> /dev/null || true
	done
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
	"$DEPLOYER_BIN" --inventory="$TEST_INVENTORY" --no-ansi server:info \
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

# ----
# Fail-Fast Support
# ----
# Sequential cloud tests (provision → install → DNS → deploy) should abort
# after the first failure to avoid wasting CI time and cloud API calls.
# Uses a sentinel file in BATS_FILE_TMPDIR (per-file, auto-cleaned by BATS).

# Mark current test as failed (call from teardown)
# BATS_TEST_COMPLETED is empty when test body failed, "1" when passed
cloud_mark_failed() {
	if [[ -z "${BATS_TEST_COMPLETED:-}" ]]; then
		printf '%s' "${BATS_TEST_DESCRIPTION:-unknown}" > "${BATS_FILE_TMPDIR}/cloud_failed"
	fi
}

# Skip remaining tests if a previous test failed (call from setup)
cloud_check_failed() {
	if [[ -f "${BATS_FILE_TMPDIR}/cloud_failed" ]]; then
		local failed_test
		failed_test=$(<"${BATS_FILE_TMPDIR}/cloud_failed")
		skip "Aborted: '${failed_test}' failed"
	fi
}

# ----
# Orchestration (cleanup all resources for a provider)
# ----

# Full AWS cleanup (most expensive first)
aws_cleanup_all() {
	aws_cleanup_test_server
	aws_cleanup_test_dns
	aws_cleanup_test_dns_raw
	cleanup_test_site "$AWS_TEST_DOMAIN"
	aws_cleanup_test_key
}

# Full DO cleanup (most expensive first)
do_cleanup_all() {
	do_cleanup_test_server
	do_cleanup_test_dns
	do_cleanup_test_dns_raw
	cleanup_test_site "$DO_TEST_DOMAIN"
	do_cleanup_test_key
}

# Full CF cleanup
cf_cleanup_all() {
	cf_cleanup_test_dns
	cf_cleanup_test_dns_raw
}
