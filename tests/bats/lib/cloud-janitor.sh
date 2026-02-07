#!/usr/bin/env bash

set -u
set -o pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/../../.." && pwd)"

MODE="sweep"
MIN_AGE_MINUTES="${MIN_AGE_MINUTES:-30}"
DRY_RUN=0
RUN_DEPLOYER_TIER="${CLOUD_JANITOR_RUN_DEPLOYER_TIER:-1}"
PROVIDERS_CSV="aws,do,cf"

declare -a REQUESTED_SUFFIXES=()
declare -A REQUESTED_PROVIDERS=()
declare -A CANDIDATE_SUFFIXES=()
declare -A PROTECTED_SUFFIXES=()

log_info() { echo "[cloud-janitor] $*"; }
log_warn() { echo "[cloud-janitor][warn] $*" >&2; }

print_cmd() {
	local out=""
	local arg
	for arg in "$@"; do out+="$(printf '%q ' "$arg")"; done
	printf '%s' "${out% }"
}

run_cmd() {
	if [[ "$DRY_RUN" == "1" ]]; then
		log_info "dry-run: $(print_cmd "$@")"
		return 0
	fi
	"$@"
}

normalize_suffix() {
	local value="$1"
	[[ -n "$value" ]] || return 1
	if [[ "$value" =~ ^[0-9]+$ ]] && (( ${#value} > 6 )); then
		echo "${value: -6}"
		return 0
	fi
	echo "$value"
}

to_lower() { printf '%s' "$1" | tr '[:upper:]' '[:lower:]'; }

add_requested_suffix() {
	local suffix
	suffix="$(normalize_suffix "$1")" || return 0
	REQUESTED_SUFFIXES+=("$suffix")
}

add_candidate_suffix() {
	local suffix
	suffix="$(normalize_suffix "$1")" || return 0
	CANDIDATE_SUFFIXES["$suffix"]=1
}

add_protected_suffix() {
	local suffix
	suffix="$(normalize_suffix "$1")" || return 0
	PROTECTED_SUFFIXES["$suffix"]=1
}

provider_enabled() {
	local provider
	provider="$(to_lower "$1")"
	[[ -n "${REQUESTED_PROVIDERS[$provider]:-}" ]]
}

record_suffix_from_value() {
	local value="$1"
	if [[ "$value" =~ ^deployer-bats-aws-([a-zA-Z0-9]+)$ ]]; then
		add_candidate_suffix "${BASH_REMATCH[1]}"
	elif [[ "$value" =~ ^deployer-bats-do-([a-zA-Z0-9]+)$ ]]; then
		add_candidate_suffix "${BASH_REMATCH[1]}"
	elif [[ "$value" =~ ^deployer-bats-run-([a-zA-Z0-9]+)$ ]]; then
		add_candidate_suffix "${BASH_REMATCH[1]}"
	elif [[ "$value" =~ ^testrunsuffix-([a-zA-Z0-9]+)$ ]]; then
		add_candidate_suffix "${BASH_REMATCH[1]}"
	elif [[ "$value" =~ ^r([a-zA-Z0-9]+)(\.|$) ]]; then
		add_candidate_suffix "${BASH_REMATCH[1]}"
	fi
}

parse_providers() {
	local item
	IFS=',' read -r -a items <<< "$1"
	for item in "${items[@]}"; do
		item="$(to_lower "${item//[[:space:]]/}")"
		case "$item" in
			aws|do|cf) REQUESTED_PROVIDERS["$item"]=1 ;;
			"") ;;
			*) log_warn "Ignoring unknown provider '${item}'" ;;
		esac
	done
}

parse_args() {
	local arg
	for arg in "$@"; do
		case "$arg" in
			--mode=*) MODE="${arg#*=}" ;;
			--suffix=*) add_requested_suffix "${arg#*=}" ;;
			--suffixes=*)
				local part
				IFS=',' read -r -a parts <<< "${arg#*=}"
				for part in "${parts[@]}"; do add_requested_suffix "${part//[[:space:]]/}"; done
				;;
			--providers=*) PROVIDERS_CSV="${arg#*=}" ;;
			--min-age-minutes=*) MIN_AGE_MINUTES="${arg#*=}" ;;
			--dry-run) DRY_RUN=1 ;;
			--skip-deployer) RUN_DEPLOYER_TIER=0 ;;
			--help)
				echo "Usage: cloud-janitor.sh [--mode=targeted|sweep] [--suffix=ID] [--suffixes=ID1,ID2] [--providers=aws,do,cf] [--min-age-minutes=30] [--dry-run]"
				exit 0
				;;
			*) log_warn "Unknown option: ${arg}"; exit 1 ;;
		esac
	done

	MODE="$(to_lower "$MODE")"
	[[ "$MODE" == "targeted" || "$MODE" == "sweep" ]] || { log_warn "Invalid mode: ${MODE}"; exit 1; }
	[[ "$MIN_AGE_MINUTES" =~ ^[0-9]+$ ]] || { log_warn "min-age-minutes must be numeric"; exit 1; }

	parse_providers "$PROVIDERS_CSV"
	[[ "${#REQUESTED_PROVIDERS[@]}" -gt 0 ]] || { log_warn "No valid providers selected"; exit 1; }
	if [[ "$MODE" == "targeted" && "${#REQUESTED_SUFFIXES[@]}" -eq 0 ]]; then
		log_warn "targeted mode requires --suffix or --suffixes"
		exit 1
	fi
}

deployer_available() {
	[[ "$RUN_DEPLOYER_TIER" == "1" ]] || return 1
	command -v php > /dev/null 2>&1 || return 1
	[[ -f "${PROJECT_ROOT}/bin/deployer" ]] || return 1
	[[ -f "${PROJECT_ROOT}/vendor/autoload.php" ]] || return 1
}

deployer_run() { php "${PROJECT_ROOT}/bin/deployer" "$@"; }
aws_cli_available() { command -v aws > /dev/null 2>&1 && command -v jq > /dev/null 2>&1; }
do_token() { printf '%s' "${DO_API_TOKEN:-${DIGITALOCEAN_API_TOKEN:-}}"; }
cf_token() { printf '%s' "${CF_API_TOKEN:-${CLOUDFLARE_API_TOKEN:-}}"; }

aws_cleanup_route53_records_for_suffix() {
	local suffix="$1"
	[[ -n "${AWS_TEST_HOSTED_ZONE:-}" ]] || return 0

	local zone_id
	zone_id=$(aws route53 list-hosted-zones-by-name --dns-name "$AWS_TEST_HOSTED_ZONE" --query "HostedZones[?Name=='${AWS_TEST_HOSTED_ZONE}.'].Id" --output text 2> /dev/null || true)
	[[ -n "$zone_id" && "$zone_id" != "None" ]] || return 0

	local dns_name fqdn record_json
	for dns_name in "r${suffix}"; do
		fqdn="${dns_name}.${AWS_TEST_HOSTED_ZONE}."
		record_json=$(aws route53 list-resource-record-sets --hosted-zone-id "$zone_id" --query "ResourceRecordSets[?Name=='${fqdn}' && Type=='A']|[0]" --output json 2> /dev/null || true)
		[[ -n "$record_json" && "$record_json" != "null" ]] || continue
		run_cmd aws route53 change-resource-record-sets --hosted-zone-id "$zone_id" --change-batch "{\"Changes\":[{\"Action\":\"DELETE\",\"ResourceRecordSet\":${record_json}}]}" > /dev/null 2>&1 || true
	done
}

cf_cleanup_records_for_suffix() {
	local suffix="$1"
	local token
	token="$(cf_token)"
	[[ -n "$token" && -n "${CF_TEST_DOMAIN:-}" ]] || return 0

	local zone_id
	zone_id=$(curl -s -H "Authorization: Bearer ${token}" "https://api.cloudflare.com/client/v4/zones?name=${CF_TEST_DOMAIN}" 2> /dev/null | jq -r '.result[0].id // empty' 2> /dev/null || true)
	[[ -n "$zone_id" ]] || return 0

	local dns_name fqdn record_id
	for dns_name in "r${suffix}"; do
		fqdn="${dns_name}.${CF_TEST_DOMAIN}"
		record_id=$(curl -s -H "Authorization: Bearer ${token}" "https://api.cloudflare.com/client/v4/zones/${zone_id}/dns_records?type=A&name=${fqdn}" 2> /dev/null | jq -r '.result[0].id // empty' 2> /dev/null || true)
		[[ -n "$record_id" ]] || continue
		run_cmd curl -s -X DELETE -H "Authorization: Bearer ${token}" "https://api.cloudflare.com/client/v4/zones/${zone_id}/dns_records/${record_id}" > /dev/null 2>&1 || true
	done
}

aws_cleanup_suffix() {
	local suffix="$1"
	local server_name="deployer-bats-aws-${suffix}"
	local inventory="${PROJECT_ROOT}/tests/bats/fixtures/inventory/cloud-aws.yml"

	if deployer_available; then
		run_cmd deployer_run --inventory="$inventory" server:delete --server="$server_name" --force --yes --destroy-cloud > /dev/null 2>&1 || true
		if [[ -n "${AWS_TEST_HOSTED_ZONE:-}" ]]; then
			run_cmd deployer_run aws:dns:delete --zone="$AWS_TEST_HOSTED_ZONE" --type=A --name="r${suffix}" --force --yes > /dev/null 2>&1 || true
			run_cmd deployer_run --inventory="$inventory" site:delete --domain="r${suffix}.${AWS_TEST_HOSTED_ZONE}" --force --yes > /dev/null 2>&1 || true
		fi
		run_cmd deployer_run aws:key:delete --key="$server_name" --force --yes > /dev/null 2>&1 || true
	fi

	if ! aws_cli_available; then
		log_warn "Skipping AWS raw cleanup (aws/jq missing)"
		return 0
	fi

	local instance_ids
	instance_ids=$(aws ec2 describe-instances --filters "Name=instance-state-name,Values=pending,running,stopping,stopped" --output json 2> /dev/null | jq -r --arg name "$server_name" --arg suffix "$suffix" '
		def hasTag($k; $v): any((.Tags // [])[]; .Key == $k and .Value == $v);
		.Reservations[]?.Instances[]? | select(hasTag("Name"; $name) or (hasTag("TestSuite"; "bats-cloud") and hasTag("TestProvider"; "aws") and hasTag("TestRunSuffix"; $suffix))) | .InstanceId
	' 2> /dev/null | sort -u)

	local volume_ids=""
	if [[ -n "$instance_ids" ]]; then
		volume_ids=$(aws ec2 describe-instances --instance-ids $instance_ids --query 'Reservations[].Instances[].BlockDeviceMappings[].Ebs.VolumeId' --output text 2> /dev/null | tr '\t' '\n' | grep -E '^[a-z0-9-]+$' || true)
		run_cmd aws ec2 terminate-instances --instance-ids $instance_ids > /dev/null 2>&1 || true
	fi

	local tagged_volume_ids
	tagged_volume_ids=$(aws ec2 describe-volumes --filters "Name=status,Values=creating,available,in-use,error" --output json 2> /dev/null | jq -r --arg name "${server_name}-root" --arg suffix "$suffix" '
		def hasTag($k; $v): any((.Tags // [])[]; .Key == $k and .Value == $v);
		.Volumes[]? | select(hasTag("Name"; $name) or (hasTag("TestSuite"; "bats-cloud") and hasTag("TestProvider"; "aws") and hasTag("TestRunSuffix"; $suffix))) | .VolumeId
	' 2> /dev/null | sort -u)

	local alloc_ids
	alloc_ids=$(aws ec2 describe-addresses --output json 2> /dev/null | jq -r --arg name "$server_name" --arg suffix "$suffix" '
		def hasTag($k; $v): any((.Tags // [])[]; .Key == $k and .Value == $v);
		.Addresses[]? | select(hasTag("Name"; $name) or (hasTag("TestSuite"; "bats-cloud") and hasTag("TestProvider"; "aws") and hasTag("TestRunSuffix"; $suffix))) | .AllocationId
	' 2> /dev/null | sort -u)

	local alloc_id
	for alloc_id in $alloc_ids; do run_cmd aws ec2 release-address --allocation-id "$alloc_id" > /dev/null 2>&1 || true; done
	run_cmd aws ec2 delete-key-pair --key-name "$server_name" > /dev/null 2>&1 || true

	aws_cleanup_route53_records_for_suffix "$suffix"

	local volume_id
	for volume_id in $volume_ids $tagged_volume_ids; do
		[[ -n "$volume_id" ]] || continue
		if [[ "$DRY_RUN" == "1" ]]; then
			log_info "dry-run: delete volume ${volume_id}"
			continue
		fi
		local attempt state
		for ((attempt = 1; attempt <= 30; attempt++)); do
			state=$(aws ec2 describe-volumes --volume-ids "$volume_id" --query 'Volumes[0].State' --output text 2> /dev/null || true)
			case "$state" in
				None|""|deleted) break ;;
				available) run_cmd aws ec2 delete-volume --volume-id "$volume_id" > /dev/null 2>&1 || true; sleep 2 ;;
				*) sleep 5 ;;
			esac
		done
	done
}

do_cleanup_suffix() {
	local suffix="$1"
	local token
	token="$(do_token)"
	[[ -n "$token" ]] || return 0

	local server_name="deployer-bats-do-${suffix}"
	local inventory="${PROJECT_ROOT}/tests/bats/fixtures/inventory/cloud-do.yml"

	if deployer_available; then
		run_cmd deployer_run --inventory="$inventory" server:delete --server="$server_name" --force --yes --destroy-cloud > /dev/null 2>&1 || true
		if [[ -n "${DO_TEST_DOMAIN:-}" ]]; then
			run_cmd deployer_run do:dns:delete --zone="$DO_TEST_DOMAIN" --type=A --name="r${suffix}" --force --yes > /dev/null 2>&1 || true
			run_cmd deployer_run --inventory="$inventory" site:delete --domain="r${suffix}.${DO_TEST_DOMAIN}" --force --yes > /dev/null 2>&1 || true
		fi
	fi

	local droplet_id
	for droplet_id in $(curl -s -H "Authorization: Bearer ${token}" "https://api.digitalocean.com/v2/droplets?per_page=200" 2> /dev/null | jq -r --arg name "$server_name" --arg suffix "$suffix" '.droplets[]? | select(.name == $name or ((.tags // []) | index("testrunsuffix-" + $suffix))) | .id' 2> /dev/null || true); do
		run_cmd curl -s -X DELETE -H "Authorization: Bearer ${token}" "https://api.digitalocean.com/v2/droplets/${droplet_id}" > /dev/null 2>&1 || true
	done

	local key_id
	for key_id in $(curl -s -H "Authorization: Bearer ${token}" "https://api.digitalocean.com/v2/account/keys?per_page=200" 2> /dev/null | jq -r --arg name "$server_name" '.ssh_keys[]? | select(.name == $name) | .id' 2> /dev/null || true); do
		run_cmd curl -s -X DELETE -H "Authorization: Bearer ${token}" "https://api.digitalocean.com/v2/account/keys/${key_id}" > /dev/null 2>&1 || true
	done

	if [[ -n "${DO_TEST_DOMAIN:-}" ]]; then
		local dns_name record_id
		for dns_name in "r${suffix}"; do
			record_id=$(curl -s -H "Authorization: Bearer ${token}" "https://api.digitalocean.com/v2/domains/${DO_TEST_DOMAIN}/records?type=A&name=${dns_name}.${DO_TEST_DOMAIN}" 2> /dev/null | jq -r '.domain_records[0].id // empty' 2> /dev/null || true)
			[[ -n "$record_id" ]] || continue
			run_cmd curl -s -X DELETE -H "Authorization: Bearer ${token}" "https://api.digitalocean.com/v2/domains/${DO_TEST_DOMAIN}/records/${record_id}" > /dev/null 2>&1 || true
		done
	fi
}

cf_cleanup_suffix() {
	local suffix="$1"
	if deployer_available && [[ -n "${CF_TEST_DOMAIN:-}" ]]; then
		run_cmd deployer_run cf:dns:delete --zone="$CF_TEST_DOMAIN" --type=A --name="r${suffix}" --force --yes > /dev/null 2>&1 || true
	fi
	cf_cleanup_records_for_suffix "$suffix"
}

cleanup_suffix() {
	local suffix="$1"
	log_info "Cleaning suffix ${suffix}"
	provider_enabled aws && aws_cleanup_suffix "$suffix"
	provider_enabled do && do_cleanup_suffix "$suffix"
	if provider_enabled cf; then
		cf_cleanup_suffix "$suffix"
	fi
}

collect_aws_suffixes() {
	aws_cli_available || return 0
	local value
	while IFS= read -r value; do record_suffix_from_value "$value"; done < <(aws ec2 describe-key-pairs --query 'KeyPairs[].KeyName' --output text 2> /dev/null | tr '\t' '\n' || true)
	while IFS= read -r value; do record_suffix_from_value "$value"; done < <(aws ec2 describe-instances --output json 2> /dev/null | jq -r '.Reservations[]?.Instances[]? | (.Tags // [])[]? | select(.Key=="Name" or .Key=="TestRunSuffix") | .Value' 2> /dev/null || true)
	while IFS= read -r value; do record_suffix_from_value "$value"; done < <(aws ec2 describe-addresses --output json 2> /dev/null | jq -r '.Addresses[]? | (.Tags // [])[]? | select(.Key=="Name" or .Key=="TestRunSuffix") | .Value' 2> /dev/null || true)
	while IFS= read -r value; do record_suffix_from_value "$value"; done < <(aws ec2 describe-volumes --output json 2> /dev/null | jq -r '.Volumes[]? | (.Tags // [])[]? | select(.Key=="Name" or .Key=="TestRunSuffix") | .Value' 2> /dev/null || true)
	if [[ -n "${AWS_TEST_HOSTED_ZONE:-}" ]]; then
		local zone_id
		zone_id=$(aws route53 list-hosted-zones-by-name --dns-name "$AWS_TEST_HOSTED_ZONE" --output json 2> /dev/null | jq -r --arg zone "${AWS_TEST_HOSTED_ZONE}." '.HostedZones[]? | select(.Name == $zone) | .Id' 2> /dev/null | head -n 1)
		if [[ -n "$zone_id" ]]; then
			while IFS= read -r value; do record_suffix_from_value "$value"; done < <(aws route53 list-resource-record-sets --hosted-zone-id "$zone_id" --output json 2> /dev/null | jq -r '.ResourceRecordSets[]? | select(.Type=="A") | .Name' 2> /dev/null || true)
		fi
	fi
}

collect_cf_suffixes() {
	local token
	token="$(cf_token)"
	[[ -n "$token" && -n "${CF_TEST_DOMAIN:-}" ]] || return 0
	command -v jq > /dev/null 2>&1 || return 0

	local zone_id
	zone_id=$(curl -s -H "Authorization: Bearer ${token}" "https://api.cloudflare.com/client/v4/zones?name=${CF_TEST_DOMAIN}" 2> /dev/null | jq -r '.result[0].id // empty' 2> /dev/null || true)
	[[ -n "$zone_id" ]] || return 0

	local value
	while IFS= read -r value; do
		record_suffix_from_value "$value"
	done < <(curl -s -H "Authorization: Bearer ${token}" "https://api.cloudflare.com/client/v4/zones/${zone_id}/dns_records?type=A&per_page=100" 2> /dev/null | jq -r '.result[]? | .name' 2> /dev/null || true)
}

collect_do_suffixes() {
	local token
	token="$(do_token)"
	[[ -n "$token" ]] || return 0
	local value
	while IFS= read -r value; do record_suffix_from_value "$value"; done < <(curl -s -H "Authorization: Bearer ${token}" "https://api.digitalocean.com/v2/droplets?per_page=200" 2> /dev/null | jq -r '.droplets[]? | .name, (.tags // [])[]?' 2> /dev/null || true)
	while IFS= read -r value; do record_suffix_from_value "$value"; done < <(curl -s -H "Authorization: Bearer ${token}" "https://api.digitalocean.com/v2/account/keys?per_page=200" 2> /dev/null | jq -r '.ssh_keys[]? | .name' 2> /dev/null || true)
	if [[ -n "${DO_TEST_DOMAIN:-}" ]]; then
		while IFS= read -r value; do record_suffix_from_value "$value"; done < <(curl -s -H "Authorization: Bearer ${token}" "https://api.digitalocean.com/v2/domains/${DO_TEST_DOMAIN}/records?type=A&per_page=200" 2> /dev/null | jq -r '.domain_records[]? | .name' 2> /dev/null || true)
	fi
}

load_protected_suffixes() {
	[[ -n "${GITHUB_TOKEN:-}" && -n "${GITHUB_REPOSITORY:-}" ]] || return 0
	local runs_json
	runs_json=$(curl -s -H "Authorization: Bearer ${GITHUB_TOKEN}" -H "Accept: application/vnd.github+json" "https://api.github.com/repos/${GITHUB_REPOSITORY}/actions/workflows/bats-cloud.yml/runs?per_page=100" 2> /dev/null || true)
	[[ -n "$runs_json" ]] || return 0
	local run_id
	while IFS= read -r run_id; do
		[[ -n "$run_id" ]] && add_protected_suffix "$run_id"
	done < <(jq -r --argjson min_age "$MIN_AGE_MINUTES" '.workflow_runs[]? | ((now - (.created_at | fromdateiso8601)) / 60) as $age | select(.status != "completed" or $age < $min_age) | (.id | tostring)' <<< "$runs_json" 2> /dev/null || true)
}

run_targeted_mode() {
	local suffix
	for suffix in "${REQUESTED_SUFFIXES[@]}"; do cleanup_suffix "$suffix"; done
}

run_sweep_mode() {
	if provider_enabled aws; then collect_aws_suffixes; fi
	if provider_enabled do; then collect_do_suffixes; fi
	if provider_enabled cf; then collect_cf_suffixes; fi
	load_protected_suffixes

	if [[ "${#CANDIDATE_SUFFIXES[@]}" -eq 0 ]]; then
		log_info "No candidate suffixes discovered"
		return 0
	fi

	local suffix
	while IFS= read -r suffix; do
		if [[ -n "${PROTECTED_SUFFIXES[$suffix]:-}" ]]; then
			log_info "Skipping protected suffix ${suffix}"
			continue
		fi
		cleanup_suffix "$suffix"
	done < <(printf '%s\n' "${!CANDIDATE_SUFFIXES[@]}" | sort)
}

main() {
	parse_args "$@"
	cd "$PROJECT_ROOT" || exit 1
	log_info "Mode=${MODE} Providers=${PROVIDERS_CSV} DryRun=${DRY_RUN} MinAge=${MIN_AGE_MINUTES}m"
	case "$MODE" in
		targeted) run_targeted_mode ;;
		sweep) run_sweep_mode ;;
	esac
}

main "$@"
