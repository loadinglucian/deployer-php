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
# Set by bats.sh before invoking bats.
# Canonical form is numeric run ID last-6 (CI) or local run-scoped fallback.
normalize_bats_run_suffix() {
	local value="${1:-}"
	local shared_tmp_dir="${BATS_SUITE_TMPDIR:-${BATS_FILE_TMPDIR:-}}"
	local shared_suffix_file=""

	if [[ -n "$value" ]] && [[ "$value" != "unknown" ]]; then
		value="${value: -6}"
		echo "$value"
		return 0
	fi

	if [[ -n "$shared_tmp_dir" ]]; then
		shared_suffix_file="${shared_tmp_dir}/bats-run-suffix"
		if [[ -f "$shared_suffix_file" ]]; then
			value="$(< "$shared_suffix_file")"
			if [[ -n "$value" ]]; then
				value="${value: -6}"
				echo "$value"
				return 0
			fi
		fi
	fi

	local suffix_seed
	suffix_seed="$(printf '%s%s%s' "$(date +%s)" "$$" "$RANDOM")"
	value="${suffix_seed: -6}"

	if [[ -n "$shared_suffix_file" ]]; then
		printf '%s' "$value" > "$shared_suffix_file"
	fi

	echo "$value"
}

export BATS_RUN_SUFFIX="$(normalize_bats_run_suffix "${BATS_RUN_SUFFIX:-}")"

# ----
# AWS Test Configuration
# ----
# Instance sizing and disk configuration

export AWS_TEST_KEY_NAME="deployer-bats-aws-${BATS_RUN_SUFFIX}"
export AWS_TEST_SERVER_NAME="deployer-bats-aws-${BATS_RUN_SUFFIX}"
export AWS_TEST_INSTANCE_TYPE="${AWS_TEST_INSTANCE_TYPE:-}"
export AWS_TEST_IMAGE="${AWS_TEST_IMAGE:-}"
export AWS_TEST_KEY_PAIR="${AWS_TEST_KEY_PAIR:-}"
export AWS_TEST_VPC="${AWS_TEST_VPC:-}"
export AWS_TEST_SUBNET="${AWS_TEST_SUBNET:-}"
export AWS_TEST_PRIVATE_KEY_PATH="${AWS_TEST_PRIVATE_KEY_PATH:-$HOME/.ssh/id_ed25519}"
export AWS_TEST_DISK_SIZE="${AWS_TEST_DISK_SIZE:-}"

# AWS DNS/Site Test Configuration
export AWS_TEST_DOMAIN="${AWS_TEST_DOMAIN:-deployeraws.eu}"
export AWS_TEST_HOSTED_ZONE="${AWS_TEST_HOSTED_ZONE:-$AWS_TEST_DOMAIN}"
export AWS_TEST_DNS_ROOT_PRIMARY="r${BATS_RUN_SUFFIX}"
export AWS_TEST_DNS_ROOT_SECONDARY="${AWS_TEST_DNS_ROOT_PRIMARY}.v2"
export AWS_TEST_DNS_ROOT="${AWS_TEST_DNS_ROOT_PRIMARY}"
export AWS_TEST_DNS_ROOT_FQDN="${AWS_TEST_DNS_ROOT}.${AWS_TEST_HOSTED_ZONE}"
export AWS_TEST_DNS_ROOT_SECONDARY_FQDN="${AWS_TEST_DNS_ROOT_SECONDARY}.${AWS_TEST_HOSTED_ZONE}"
export AWS_TEST_SITE_DOMAIN="${AWS_TEST_DNS_ROOT_FQDN}"
export AWS_TEST_SITE_DOMAIN_SECONDARY="${AWS_TEST_DNS_ROOT_SECONDARY_FQDN}"

# ----
# DigitalOcean Test Configuration
# ----
# Droplet sizing and VPC configuration

export DO_TEST_KEY_NAME="deployer-bats-do-${BATS_RUN_SUFFIX}"
export DO_TEST_SERVER_NAME="deployer-bats-do-${BATS_RUN_SUFFIX}"
export DO_TEST_SSH_KEY_ID="${DO_TEST_SSH_KEY_ID:-}"
export DO_TEST_PRIVATE_KEY_PATH="${DO_TEST_PRIVATE_KEY_PATH:-$HOME/.ssh/id_ed25519}"
export DO_TEST_REGION="${DO_TEST_REGION:-}"
export DO_TEST_SIZE="${DO_TEST_SIZE:-}"
export DO_TEST_IMAGE="${DO_TEST_IMAGE:-}"
export DO_TEST_VPC_UUID="${DO_TEST_VPC_UUID:-}"

# DigitalOcean DNS/Site Test Configuration
export DO_TEST_DOMAIN="${DO_TEST_DOMAIN:-deployerdo.eu}"
export DO_TEST_DNS_ROOT_PRIMARY="r${BATS_RUN_SUFFIX}"
export DO_TEST_DNS_ROOT_SECONDARY="${DO_TEST_DNS_ROOT_PRIMARY}.v2"
export DO_TEST_DNS_ROOT="${DO_TEST_DNS_ROOT_PRIMARY}"
export DO_TEST_DNS_ROOT_FQDN="${DO_TEST_DNS_ROOT}.${DO_TEST_DOMAIN}"
export DO_TEST_DNS_ROOT_SECONDARY_FQDN="${DO_TEST_DNS_ROOT_SECONDARY}.${DO_TEST_DOMAIN}"
export DO_TEST_SITE_DOMAIN="${DO_TEST_DNS_ROOT_FQDN}"
export DO_TEST_SITE_DOMAIN_SECONDARY="${DO_TEST_DNS_ROOT_SECONDARY_FQDN}"

# ----
# Cloudflare Test Configuration
# ----
# DNS-only provider - uses AWS-provisioned server IP for record values

export CF_TEST_DOMAIN="${CF_TEST_DOMAIN:-deployercf.eu}"
export CF_TEST_DNS_ROOT="r${BATS_RUN_SUFFIX}"
export CF_TEST_DNS_ROOT_FQDN="${CF_TEST_DNS_ROOT}.${CF_TEST_DOMAIN}"
export CF_TEST_SITE_DOMAIN="${CF_TEST_DNS_ROOT_FQDN}"

# ----
# Shared Deployment Test Configuration
# ----

export CLOUD_TEST_PHP_EXTENSIONS="${CLOUD_TEST_PHP_EXTENSIONS:-fpm,bcmath,curl,mbstring,xml,zip}"
export CLOUD_TEST_PHP_PRIMARY_VERSION="${CLOUD_TEST_PHP_PRIMARY_VERSION:-8.5}"
export CLOUD_TEST_PHP_SECONDARY_VERSION="${CLOUD_TEST_PHP_SECONDARY_VERSION:-8.4}"
export CLOUD_TEST_DEPLOY_REPO="${CLOUD_TEST_DEPLOY_REPO:-https://github.com/loadinglucian/deploy-me.git}"
export CLOUD_TEST_DEPLOY_BRANCH="${CLOUD_TEST_DEPLOY_BRANCH:-main}"
export CLOUD_TEST_APP_MESSAGE="${CLOUD_TEST_APP_MESSAGE:-DeployerPHP-BATS-Test-Success}"

# ----
# Tag Contract (cleanup discovery)
# ----

export CLOUD_TEST_TAG_MANAGED_BY="${CLOUD_TEST_TAG_MANAGED_BY:-deployer}"
export CLOUD_TEST_TAG_TEST_SUITE="${CLOUD_TEST_TAG_TEST_SUITE:-bats-cloud}"
export CLOUD_TEST_TAG_AWS_PROVIDER="${CLOUD_TEST_TAG_AWS_PROVIDER:-aws}"
export CLOUD_TEST_TAG_DO_PROVIDER="${CLOUD_TEST_TAG_DO_PROVIDER:-do}"

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

# Require run-scoped prefixed DNS labels/domains for parallel safety
require_prefixed_dns_config() {
	local provider="$1"
	local zone="$2"
	local root_label="$3"
	local root_fqdn="$4"
	local site_domain="$5"
	local secondary_label="${6:-}"
	local secondary_fqdn="${7:-}"
	local secondary_site_domain="${8:-}"
	local expected_root="r${BATS_RUN_SUFFIX}"

	if [[ "$root_label" != "$expected_root" ]]; then
		echo "${provider} DNS root label must be '${expected_root}', got '${root_label}'"
		return 1
	fi

	if [[ "$root_fqdn" != "${root_label}.${zone}" ]]; then
		echo "${provider} root FQDN must be '${root_label}.${zone}', got '${root_fqdn}'"
		return 1
	fi

	if [[ "$site_domain" != "$root_fqdn" ]]; then
		echo "${provider} site domain must be '${root_fqdn}', got '${site_domain}'"
		return 1
	fi

	if [[ -n "$secondary_label" ]]; then
		local expected_secondary_label="${root_label}.v2"
		if [[ "$secondary_label" != "$expected_secondary_label" ]]; then
			echo "${provider} secondary DNS label must be '${expected_secondary_label}', got '${secondary_label}'"
			return 1
		fi
	fi

	if [[ -n "$secondary_fqdn" ]]; then
		local expected_secondary_fqdn="${secondary_label}.${zone}"
		if [[ "$secondary_fqdn" != "$expected_secondary_fqdn" ]]; then
			echo "${provider} secondary FQDN must be '${expected_secondary_fqdn}', got '${secondary_fqdn}'"
			return 1
		fi
	fi

	if [[ -n "$secondary_site_domain" ]] && [[ "$secondary_site_domain" != "$secondary_fqdn" ]]; then
		echo "${provider} secondary site domain must be '${secondary_fqdn}', got '${secondary_site_domain}'"
		return 1
	fi

	return 0
}

require_aws_dns_prefix_config() {
	require_prefixed_dns_config \
		"AWS" \
		"$AWS_TEST_HOSTED_ZONE" \
		"$AWS_TEST_DNS_ROOT" \
		"$AWS_TEST_DNS_ROOT_FQDN" \
		"$AWS_TEST_SITE_DOMAIN" \
		"$AWS_TEST_DNS_ROOT_SECONDARY" \
		"$AWS_TEST_DNS_ROOT_SECONDARY_FQDN" \
		"$AWS_TEST_SITE_DOMAIN_SECONDARY"
}

require_do_dns_prefix_config() {
	require_prefixed_dns_config \
		"DigitalOcean" \
		"$DO_TEST_DOMAIN" \
		"$DO_TEST_DNS_ROOT" \
		"$DO_TEST_DNS_ROOT_FQDN" \
		"$DO_TEST_SITE_DOMAIN" \
		"$DO_TEST_DNS_ROOT_SECONDARY" \
		"$DO_TEST_DNS_ROOT_SECONDARY_FQDN" \
		"$DO_TEST_SITE_DOMAIN_SECONDARY"
}

require_cf_dns_prefix_config() {
	require_prefixed_dns_config \
		"Cloudflare" \
		"$CF_TEST_DOMAIN" \
		"$CF_TEST_DNS_ROOT" \
		"$CF_TEST_DNS_ROOT_FQDN" \
		"$CF_TEST_SITE_DOMAIN"
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
	local name
	for name in "$AWS_TEST_DNS_ROOT" "$AWS_TEST_DNS_ROOT_SECONDARY"; do
		"$DEPLOYER_BIN" aws:dns:delete \
			--zone="$AWS_TEST_HOSTED_ZONE" \
			--type="A" \
			--name="$name" \
			--force \
			--yes 2> /dev/null || true
	done
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

	for name in "$AWS_TEST_DNS_ROOT" "$AWS_TEST_DNS_ROOT_SECONDARY"; do
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
	local name
	for name in "$DO_TEST_DNS_ROOT" "$DO_TEST_DNS_ROOT_SECONDARY"; do
		"$DEPLOYER_BIN" do:dns:delete \
			--zone="$DO_TEST_DOMAIN" \
			--type="A" \
			--name="$name" \
			--force \
			--yes 2> /dev/null || true
	done
}

# Cleanup DO test DNS records via raw DO API (safety net)
do_cleanup_test_dns_raw() {
	local token="${DO_API_TOKEN:-${DIGITALOCEAN_API_TOKEN:-}}"
	[[ -n "$token" ]] || return 0

	local domain="$DO_TEST_DOMAIN"

	for name in "$DO_TEST_DNS_ROOT" "$DO_TEST_DNS_ROOT_SECONDARY"; do
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

	for name in "$CF_TEST_DNS_ROOT"; do
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

get_server_command_output() {
	local server_name="$1"
	local command="$2"
	"$DEPLOYER_BIN" --inventory="$TEST_INVENTORY" --no-ansi server:run \
		--server="$server_name" \
		--command="$command" 2> /dev/null
}

get_installed_php_fpm_versions_for_server() {
	local server_name="$1"
	local output
	local command="ls -1 /etc/php/*/fpm/php-fpm.conf 2>/dev/null | sed -nE 's|/etc/php/([^/]+)/fpm/php-fpm.conf|\\1|p' | sort -Vr | uniq || true"

	output=$(get_server_command_output "$server_name" "$command") || {
		echo "Failed to discover installed PHP-FPM versions on server '${server_name}'" >&2
		return 1
	}

	printf '%s\n' "$output" \
		| sed -nE 's/^[^0-9]*([0-9]+\.[0-9]+)[^0-9]*$/\1/p' \
		| grep -E '^8\.[0-9]+$' \
		| sort -Vr \
		| uniq
}

cloud_note() {
	printf '# %s\n' "$*" >&3
}

log_aws_cloud_targets() {
	cloud_note "Run suffix: ${BATS_RUN_SUFFIX}"
	cloud_note "AWS site domain: ${AWS_TEST_SITE_DOMAIN}"
	cloud_note "AWS secondary site domain: ${AWS_TEST_SITE_DOMAIN_SECONDARY}"
	cloud_note "AWS DNS A record: ${AWS_TEST_DNS_ROOT_FQDN}"
	cloud_note "AWS secondary DNS A record: ${AWS_TEST_DNS_ROOT_SECONDARY_FQDN}"
}

log_do_cloud_targets() {
	cloud_note "Run suffix: ${BATS_RUN_SUFFIX}"
	cloud_note "DO site domain: ${DO_TEST_SITE_DOMAIN}"
	cloud_note "DO secondary site domain: ${DO_TEST_SITE_DOMAIN_SECONDARY}"
	cloud_note "DO DNS A record: ${DO_TEST_DNS_ROOT_FQDN}"
	cloud_note "DO secondary DNS A record: ${DO_TEST_DNS_ROOT_SECONDARY_FQDN}"
}

log_cf_cloud_targets() {
	cloud_note "Run suffix: ${BATS_RUN_SUFFIX}"
	cloud_note "CF DNS A record: ${CF_TEST_DNS_ROOT_FQDN}"
}

assert_aws_tag_contract() {
	local tags_json="$1"
	local key="$2"
	local value="$3"

	if ! jq -e --arg k "$key" --arg v "$value" 'any(.[]?; .Key == $k and .Value == $v)' > /dev/null <<< "$tags_json"; then
		echo "Missing AWS tag ${key}=${value}"
		echo "Actual tags: ${tags_json}"
		return 1
	fi
}

aws_find_instance_id_by_name() {
	local server_name="$1"
	local instance_id
	instance_id=$(aws ec2 describe-instances \
		--filters \
		"Name=tag:Name,Values=${server_name}" \
		"Name=instance-state-name,Values=pending,running,stopping,stopped" \
		--query 'Reservations[].Instances[].InstanceId' \
		--output text 2> /dev/null || true)

	[[ "$instance_id" == "None" ]] && return 1
	[[ -n "$instance_id" ]] || return 1
	echo "$instance_id" | awk '{ print $1 }'
}

aws_assert_instance_tag_contract() {
	local server_name="$1"
	local instance_id tags_json
	instance_id=$(aws_find_instance_id_by_name "$server_name") || {
		echo "AWS instance not found for tag assertions: ${server_name}"
		return 1
	}

	tags_json=$(aws ec2 describe-instances \
		--instance-ids "$instance_id" \
		--query 'Reservations[0].Instances[0].Tags' \
		--output json 2> /dev/null || echo "[]")

	assert_aws_tag_contract "$tags_json" "Name" "$server_name"
	assert_aws_tag_contract "$tags_json" "ManagedBy" "$CLOUD_TEST_TAG_MANAGED_BY"
	assert_aws_tag_contract "$tags_json" "TestSuite" "$CLOUD_TEST_TAG_TEST_SUITE"
	assert_aws_tag_contract "$tags_json" "TestProvider" "$CLOUD_TEST_TAG_AWS_PROVIDER"
	assert_aws_tag_contract "$tags_json" "TestRunSuffix" "$BATS_RUN_SUFFIX"
}

aws_find_eip_allocation_id_by_name() {
	local server_name="$1"
	local allocation_id
	allocation_id=$(aws ec2 describe-addresses \
		--filters "Name=tag:Name,Values=${server_name}" \
		--query 'Addresses[].AllocationId' \
		--output text 2> /dev/null || true)

	[[ "$allocation_id" == "None" ]] && return 1
	[[ -n "$allocation_id" ]] || return 1
	echo "$allocation_id" | awk '{ print $1 }'
}

aws_assert_eip_tag_contract() {
	local server_name="$1"
	local allocation_id tags_json
	allocation_id=$(aws_find_eip_allocation_id_by_name "$server_name") || {
		echo "AWS Elastic IP not found for tag assertions: ${server_name}"
		return 1
	}

	tags_json=$(aws ec2 describe-addresses \
		--allocation-ids "$allocation_id" \
		--query 'Addresses[0].Tags' \
		--output json 2> /dev/null || echo "[]")

	assert_aws_tag_contract "$tags_json" "Name" "$server_name"
	assert_aws_tag_contract "$tags_json" "ManagedBy" "$CLOUD_TEST_TAG_MANAGED_BY"
	assert_aws_tag_contract "$tags_json" "TestSuite" "$CLOUD_TEST_TAG_TEST_SUITE"
	assert_aws_tag_contract "$tags_json" "TestProvider" "$CLOUD_TEST_TAG_AWS_PROVIDER"
	assert_aws_tag_contract "$tags_json" "TestRunSuffix" "$BATS_RUN_SUFFIX"
}

aws_assert_root_volume_tag_contract() {
	local server_name="$1"
	local instance_id volume_id tags_json
	instance_id=$(aws_find_instance_id_by_name "$server_name") || {
		echo "AWS instance not found when checking root volume tags: ${server_name}"
		return 1
	}

	volume_id=$(aws ec2 describe-instances \
		--instance-ids "$instance_id" \
		--query 'Reservations[0].Instances[0].BlockDeviceMappings[0].Ebs.VolumeId' \
		--output text 2> /dev/null || true)

	[[ "$volume_id" == "None" ]] && {
		echo "Root volume ID not found for instance ${instance_id}"
		return 1
	}
	[[ -n "$volume_id" ]] || {
		echo "Root volume ID is empty for instance ${instance_id}"
		return 1
	}

	tags_json=$(aws ec2 describe-volumes \
		--volume-ids "$volume_id" \
		--query 'Volumes[0].Tags' \
		--output json 2> /dev/null || echo "[]")

	assert_aws_tag_contract "$tags_json" "Name" "${server_name}-root"
	assert_aws_tag_contract "$tags_json" "ManagedBy" "$CLOUD_TEST_TAG_MANAGED_BY"
	assert_aws_tag_contract "$tags_json" "TestSuite" "$CLOUD_TEST_TAG_TEST_SUITE"
	assert_aws_tag_contract "$tags_json" "TestProvider" "$CLOUD_TEST_TAG_AWS_PROVIDER"
	assert_aws_tag_contract "$tags_json" "TestRunSuffix" "$BATS_RUN_SUFFIX"
}

aws_assert_no_bats_volumes() {
	local run_suffix="${1:-$BATS_RUN_SUFFIX}"
	local attempts="${2:-24}"
	local sleep_seconds="${3:-5}"
	local volume_ids=""

	for ((attempt = 1; attempt <= attempts; attempt++)); do
		volume_ids=$(aws ec2 describe-volumes \
			--filters \
			"Name=tag:TestSuite,Values=${CLOUD_TEST_TAG_TEST_SUITE}" \
			"Name=tag:TestProvider,Values=${CLOUD_TEST_TAG_AWS_PROVIDER}" \
			"Name=tag:TestRunSuffix,Values=${run_suffix}" \
			"Name=status,Values=creating,available,in-use,error" \
			--query 'Volumes[].VolumeId' \
			--output text 2> /dev/null || true)

		if [[ -z "$volume_ids" || "$volume_ids" == "None" ]]; then
			return 0
		fi

		if [[ "$attempt" -lt "$attempts" ]]; then
			sleep "$sleep_seconds"
		fi
	done

	echo "Expected no BATS volumes for suffix ${run_suffix}, found: ${volume_ids}"
	return 1
}

do_assert_droplet_tag_contract() {
	local server_name="$1"
	local token="${DO_API_TOKEN:-${DIGITALOCEAN_API_TOKEN:-}}"
	[[ -n "$token" ]] || {
		echo "DigitalOcean API token missing for droplet tag assertions"
		return 1
	}

	local tags_json
	tags_json=$(curl -s -H "Authorization: Bearer ${token}" \
		"https://api.digitalocean.com/v2/droplets?per_page=200" 2> /dev/null \
		| jq -c --arg name "$server_name" '.droplets[]? | select(.name == $name) | (.tags // [])' 2> /dev/null \
		| head -n 1)

	[[ -n "$tags_json" ]] || {
		echo "DigitalOcean droplet not found for tag assertions: ${server_name}"
		return 1
	}

	for expected in \
		"managedby-${CLOUD_TEST_TAG_MANAGED_BY}" \
		"name-${server_name}" \
		"testsuite-${CLOUD_TEST_TAG_TEST_SUITE}" \
		"testprovider-${CLOUD_TEST_TAG_DO_PROVIDER}" \
		"testrunsuffix-${BATS_RUN_SUFFIX}"; do
		if ! jq -e --arg expected "$expected" 'any(.[]?; . == $expected)' > /dev/null <<< "$tags_json"; then
			echo "Missing DO droplet tag: ${expected}"
			echo "Actual tags: ${tags_json}"
			return 1
		fi
	done
}

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

# Wait for DNS A record resolution with optional expected IP verification
# Usage: wait_for_dns_a_record "example.com" "1.2.3.4" 300
wait_for_dns_a_record() {
	local domain="$1"
	local expected_ip="${2:-}"
	local timeout="${3:-300}"
	local interval=10
	local elapsed=0
	local last_ips=""

	while [[ $elapsed -lt $timeout ]]; do
		local response ips
		response=$(curl -s --max-time 10 "https://dns.google/resolve?name=${domain}&type=A" 2> /dev/null || true)
		ips=$(echo "$response" \
			| jq -r '.Answer[]? | select(.type == 1) | .data' 2> /dev/null \
			| tr '\n' ' ' \
			| sed 's/[[:space:]]\+$//' || true)

		last_ips="$ips"

		if [[ -n "$ips" ]]; then
			if [[ -z "$expected_ip" ]] || [[ " ${ips} " == *" ${expected_ip} "* ]]; then
				echo "DNS A record resolved for ${domain}: ${ips}"
				return 0
			fi
		fi

		sleep $interval
		elapsed=$((elapsed + interval))
	done

	echo "Timeout waiting for DNS A record for ${domain} after ${timeout}s"
	[[ -n "$expected_ip" ]] && echo "Expected IP: ${expected_ip}"
	if [[ -n "$last_ips" ]]; then
		echo "Last resolved IPs: ${last_ips}"
	else
		echo "No A records resolved"
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
		failed_test=$(< "${BATS_FILE_TMPDIR}/cloud_failed")
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
	cleanup_test_site "$AWS_TEST_SITE_DOMAIN"
	cleanup_test_site "$AWS_TEST_SITE_DOMAIN_SECONDARY"
	aws_cleanup_test_key
}

# Full DO cleanup (most expensive first)
do_cleanup_all() {
	do_cleanup_test_server
	do_cleanup_test_dns
	do_cleanup_test_dns_raw
	cleanup_test_site "$DO_TEST_SITE_DOMAIN"
	cleanup_test_site "$DO_TEST_SITE_DOMAIN_SECONDARY"
	do_cleanup_test_key
}

# Full CF cleanup
cf_cleanup_all() {
	cf_cleanup_test_dns
	cf_cleanup_test_dns_raw
}
