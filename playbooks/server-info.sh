#!/usr/bin/env bash
#
# Gather Server Information
# ----
# This playbook detects distribution, family, permissions, hardware info, listening services, Caddy metrics, and PHP-FPM metrics.
#
# Required Environment Variables:
#   DEPLOYER_OUTPUT_FILE - Output file path (provided automatically)
#
# Returns YAML with:
#   - distro: ubuntu|debian|fedora|centos|rocky|alma|rhel|amazon|unknown
#   - family: debian|fedora|redhat|amazon|unknown
#   - permissions: root|sudo|none
#   - hardware: cpu_cores, ram_mb, disk_type
#   - php: versions array (version, extensions), default version
#   - caddy: Caddy metrics (available, version, sites_count, domains, uptime_seconds, active_requests, total_requests, memory_mb)
#   - php_fpm: map of PHP versions to metrics (pool, process_manager, uptime_seconds, accepted_conn, listen_queue, idle_processes, active_processes, total_processes, max_children_reached, slow_requests)
#   - ports: map of port numbers to process names
#   - sites_config: map of domain to config (php_version, www_mode, https_enabled)

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

# Validation
if [[ -z $DEPLOYER_OUTPUT_FILE ]]; then
	echo "Error: DEPLOYER_OUTPUT_FILE required"
	exit 1
fi

# Shared helpers are automatically inlined when executing playbooks remotely
# source "$(dirname "$0")/helpers.sh"

# ----
# Detection Functions
# ----

#
# Distribution Detection
# ----

#
# Detect Linux Distribution
# Returns: exact distribution name (ubuntu|debian|fedora|centos|rocky|alma|rhel|amazon|unknown)

detect_distro() {
	local distro='unknown'

	if [[ -f /etc/os-release ]]; then
		# Try to get the ID field first for exact distro name
		if grep -q '^ID=' /etc/os-release; then
			distro=$(grep '^ID=' /etc/os-release | cut -d'=' -f2 | tr -d '"' | tr -d "'")

			# Normalize some common variations
			case $distro in
				almalinux) distro='alma' ;;
				rocky | rockylinux) distro='rocky' ;;
				rhel | redhat) distro='rhel' ;;
				amzn) distro='amazon' ;;
			esac
		fi
	elif [[ -f /etc/redhat-release ]]; then
		# Fallback for older systems without /etc/os-release
		if grep -qi 'centos' /etc/redhat-release; then
			distro='centos'
		elif grep -qi 'red hat' /etc/redhat-release; then
			distro='rhel'
		else
			distro='unknown'
		fi
	elif [[ -f /etc/debian_version ]]; then
		# Fallback for Debian systems
		distro='debian'
	fi

	echo "$distro"
}

#
# Detect Distribution Family
# Returns: debian|fedora|redhat|amazon|unknown

detect_family() {
	local distro=$1
	local family='unknown'

	case $distro in
		ubuntu | debian)
			family='debian'
			;;
		fedora)
			family='fedora'
			;;
		centos | rocky | alma | rhel)
			family='redhat'
			;;
		amazon)
			family='amazon'
			;;
	esac

	echo "$family"
}

#
# Permission Detection
# ----

#
# Check User Permissions
# Returns: root|sudo|none
# Note: sudo -n checks for passwordless sudo (non-interactive)

check_permissions() {
	if [[ $EUID -eq 0 ]]; then
		echo 'root'
	elif sudo -n true 2> /dev/null; then
		echo 'sudo'
	else
		echo 'none'
	fi
}

# ----
# Helper Functions
# ----

#
# Hardware Detection
# ----

#
# Detect CPU core count

detect_cpu_cores() {
	nproc 2> /dev/null || echo "1"
}

#
# Detect total system RAM in MB

detect_ram_mb() {
	free -m 2> /dev/null | awk 'NR==2 {print $2}' || echo "512"
}

#
# Detect disk type (ssd or hdd)

detect_disk_type() {
	local disk_gran rotation

	# Primary detection: Check discard granularity (TRIM support = SSD)
	# Works reliably in virtualized environments (cloud VMs)
	disk_gran=$(lsblk -d -o name,disc-gran 2> /dev/null | grep -E "^([sv]d|nvme)" | head -n1 | awk '{print $2}')

	# If disc-gran is non-zero (e.g., "512B"), it's an SSD
	if [[ -n $disk_gran && $disk_gran != "0B" ]]; then
		echo "ssd"
		return
	fi

	# Fallback: Check rotation flag (works for physical disks)
	rotation=$(lsblk -d -o name,rota 2> /dev/null | grep -E "^([sv]d|nvme)" | head -n1 | awk '{print $2}')
	if [[ $rotation == "0" ]]; then
		echo "ssd"
	else
		echo "hdd"
	fi
}

#
# PHP Detection
# ----

#
# Detect installed PHP versions

detect_php_versions() {
	local version_list=()

	# Find all php binaries in /usr/bin
	while IFS= read -r binary; do
		# Extract version from binary name (e.g., php8.4 -> 8.4)
		if [[ $binary =~ php([0-9]+\.[0-9]+)$ ]]; then
			version_list+=("${BASH_REMATCH[1]}")
		fi
	done < <(find /usr/bin -maxdepth 1 -name 'php[0-9]*' -type f 2> /dev/null | sort -V)

	# Return comma-separated list
	if ((${#version_list[@]} > 0)); then
		printf '%s' "$(
			IFS=,
			echo "${version_list[*]}"
		)"
	fi
}

#
# Detect PHP extensions for a specific version

detect_php_extensions() {
	local php_version=$1
	local extensions=()

	# Get extensions using php -m for the specific version
	if command -v "php${php_version}" > /dev/null 2>&1; then
		while IFS= read -r ext; do
			[[ -n $ext ]] && extensions+=("$ext")
		done < <("php${php_version}" -m 2> /dev/null | grep -v '^\[' | grep -v '^$')
	fi

	# Return comma-separated list
	if ((${#extensions[@]} > 0)); then
		printf '%s' "$(
			IFS=,
			echo "${extensions[*]}"
		)"
	fi
}

# ----
# Service Metrics
# ----

#
# Caddy Metrics
# ----

get_caddy_metrics() {
	# Check if Caddy admin API is available on port 2019
	if ! curl -sf --max-time 2 http://localhost:2019/config/ > /dev/null 2>&1; then
		return 0
	fi

	# Get version
	local version
	version=$(caddy version 2> /dev/null | head -n1 | awk '{print $1}')
	[[ -z $version ]] && version="unknown"

	# Get config to count sites and extract domains
	local config sites_count domains
	config=$(curl -sf --max-time 2 http://localhost:2019/config/apps/http/servers 2> /dev/null || echo "{}")

	# Count configured sites (count server entries)
	sites_count=$(echo "$config" | grep -c '"listen"' 2> /dev/null)
	[[ -z $sites_count ]] && sites_count="0"

	# Extract listening addresses/domains (simplified - gets host matchers)
	domains=$(echo "$config" | grep -o '"host":\s*\["[^"]*"\]' | sed 's/"host":\s*\["\([^"]*\)"\]/\1/g' | tr '\n' ',' | sed 's/,$//' || echo "")

	# Get uptime from process (Caddy process start time)
	local uptime_seconds caddy_pid
	if caddy_pid=$(pgrep -x caddy 2> /dev/null | head -n1); then
		uptime_seconds=$(ps -p "$caddy_pid" -o etimes= 2> /dev/null | tr -d ' ')
		[[ -z $uptime_seconds ]] && uptime_seconds="0"
	else
		uptime_seconds="0"
	fi

	# Get basic metrics from admin API
	local metrics active_requests total_requests
	metrics=$(curl -sf --max-time 2 http://localhost:2019/metrics 2> /dev/null || echo "")

	# Parse Prometheus metrics for useful stats
	# Active requests: sum of caddy_http_requests_in_flight (across all handlers)
	active_requests=$(echo "$metrics" | grep '^caddy_http_requests_in_flight{' | awk '{sum+=$2} END {print sum+0}')
	[[ -z $active_requests ]] && active_requests="0"

	# Total requests: sum across all handlers
	total_requests=$(echo "$metrics" | grep '^caddy_http_requests_total{' | awk '{sum+=$2} END {print sum+0}')
	[[ -z $total_requests ]] && total_requests="0"

	# Memory usage (process RSS in MB)
	local memory_mb
	if [[ -n $caddy_pid ]]; then
		memory_mb=$(ps -p "$caddy_pid" -o rss= 2> /dev/null | awk '{printf "%.1f", $1/1024}')
		[[ -z $memory_mb ]] && memory_mb="0"
	else
		memory_mb="0"
	fi

	# Output as tab-separated values (tabs can't appear in version/domain strings)
	printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$version" "$sites_count" "$domains" "$uptime_seconds" "$active_requests" "$total_requests" "$memory_mb"
}

#
# PHP-FPM Metrics
# ----

get_php_fpm_metrics() {
	local php_version=$1

	# Check if PHP-FPM status endpoint is available for this version
	if ! curl -sf --max-time 2 "http://localhost:9001/php${php_version}/fpm-status" > /dev/null 2>&1; then
		return 0
	fi

	# Get status in JSON format
	local status
	status=$(curl -sf --max-time 2 "http://localhost:9001/php${php_version}/fpm-status?json" 2> /dev/null || echo "{}")

	# Parse JSON fields using grep/sed (simple parsing without jq dependency)
	local pool process_manager start_since accepted_conn
	local listen_queue idle_processes active_processes total_processes
	local max_children_reached slow_requests

	pool=$(echo "$status" | grep -o '"pool":"[^"]*"' | cut -d'"' -f4)
	[[ -z $pool ]] && pool="www"

	process_manager=$(echo "$status" | grep -o '"process manager":"[^"]*"' | cut -d'"' -f4)
	[[ -z $process_manager ]] && process_manager="unknown"

	start_since=$(echo "$status" | grep -o '"start since":[0-9]*' | awk -F: '{print $2}')
	[[ -z $start_since ]] && start_since="0"

	accepted_conn=$(echo "$status" | grep -o '"accepted conn":[0-9]*' | awk -F: '{print $2}')
	[[ -z $accepted_conn ]] && accepted_conn="0"

	listen_queue=$(echo "$status" | grep -o '"listen queue":[0-9]*' | awk -F: '{print $2}')
	[[ -z $listen_queue ]] && listen_queue="0"

	idle_processes=$(echo "$status" | grep -o '"idle processes":[0-9]*' | awk -F: '{print $2}')
	[[ -z $idle_processes ]] && idle_processes="0"

	active_processes=$(echo "$status" | grep -o '"active processes":[0-9]*' | awk -F: '{print $2}')
	[[ -z $active_processes ]] && active_processes="0"

	total_processes=$(echo "$status" | grep -o '"total processes":[0-9]*' | awk -F: '{print $2}')
	[[ -z $total_processes ]] && total_processes="0"

	max_children_reached=$(echo "$status" | grep -o '"max children reached":[0-9]*' | awk -F: '{print $2}')
	[[ -z $max_children_reached ]] && max_children_reached="0"

	slow_requests=$(echo "$status" | grep -o '"slow requests":[0-9]*' | awk -F: '{print $2}')
	[[ -z $slow_requests ]] && slow_requests="0"

	# Output as tab-separated values (tabs can't appear in pool/pm names)
	printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$pool" "$process_manager" "$start_since" "$accepted_conn" "$listen_queue" "$idle_processes" "$active_processes" "$total_processes" "$max_children_reached" "$slow_requests"
}

#
# Sites Configuration Detection
# ----

get_sites_config() {
	local sites_dir="/etc/caddy/conf.d/sites"

	if [[ ! -d "$sites_dir" ]]; then
		return
	fi

	for config_file in "$sites_dir"/*.caddy; do
		[[ -f "$config_file" ]] || continue

		local domain
		domain=$(basename "$config_file" .caddy)

		# Read file content
		local content
		content=$(cat "$config_file")

		# PHP Version
		local php_version="unknown"
		if [[ $content =~ php([0-9]+\.[0-9]+)-fpm\.sock ]]; then
			php_version="${BASH_REMATCH[1]}"
		fi

		# WWW Mode
		local www_mode="unknown"
		if [[ $content =~ "Redirect www -> root" ]]; then
			www_mode="redirect-to-root"
		elif [[ $content =~ "Redirect root -> www" ]]; then
			www_mode="redirect-to-www"
		fi

		# HTTPS Status
		local https_enabled="true"

		# IP-based HTTP-only site (e.g., status page listening on http://:80)
		if [[ $content =~ http://:80 ]]; then
			https_enabled="false"
		elif [[ $www_mode == "redirect-to-root" ]]; then
			if [[ $content =~ http://${domain} ]]; then
				https_enabled="false"
			fi
		elif [[ $www_mode == "redirect-to-www" ]]; then
			if [[ $content =~ http://www.${domain} ]]; then
				https_enabled="false"
			fi
		else
			if [[ $content =~ http://${domain} || $content =~ http://www.${domain} ]]; then
				https_enabled="false"
			fi
		fi

		printf '%s\t%s\t%s\t%s\n' "$domain" "$php_version" "$www_mode" "$https_enabled"
	done
}

# ----
# Main Execution
# ----

main() {
	local distro family permissions
	local cpu_cores ram_mb disk_type
	local php_versions php_default

	#
	# Gather basic info

	echo "→ Detecting distribution..."
	distro=$(detect_distro)
	family=$(detect_family "$distro")

	echo "→ Checking permissions..."
	permissions=$(check_permissions)

	echo "→ Detecting hardware..."
	cpu_cores=$(detect_cpu_cores)
	ram_mb=$(detect_ram_mb)
	disk_type=$(detect_disk_type)

	echo "→ Detecting PHP versions..."
	php_versions=$(detect_php_versions)
	php_default=$(detect_php_default)

	echo "→ Checking Caddy status..."
	local caddy_metrics caddy_available="false"
	local caddy_version caddy_sites caddy_domains caddy_uptime caddy_active_req caddy_total_req caddy_memory
	caddy_metrics=$(get_caddy_metrics)

	if [[ -n $caddy_metrics ]]; then
		caddy_available="true"
		IFS=$'\t' read -r caddy_version caddy_sites caddy_domains caddy_uptime caddy_active_req caddy_total_req caddy_memory <<< "$caddy_metrics"
	fi

	echo "→ Checking PHP-FPM status..."
	local php_fpm_yaml="" has_fpm_metrics=false
	if [[ -n $php_versions ]]; then
		IFS=',' read -ra version_array <<< "$php_versions"
		for version in "${version_array[@]}"; do
			local fpm_metrics
			fpm_metrics=$(get_php_fpm_metrics "$version")

			if [[ -n $fpm_metrics ]]; then
				has_fpm_metrics=true
				local pool pm uptime accepted queue idle active total max_children slow
				IFS=$'\t' read -r pool pm uptime accepted queue idle active total max_children slow <<< "$fpm_metrics"

				php_fpm_yaml+="  \"${version}\":
    pool: ${pool}
    process_manager: ${pm}
    uptime_seconds: ${uptime}
    accepted_conn: ${accepted}
    listen_queue: ${queue}
    idle_processes: ${idle}
    active_processes: ${active}
    total_processes: ${total}
    max_children_reached: ${max_children}
    slow_requests: ${slow}
"
			fi
		done
	fi

	#
	# Output YAML to file

	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		distro: $distro
		family: $family
		permissions: $permissions
		hardware:
		  cpu_cores: $cpu_cores
		  ram_mb: $ram_mb
		  disk_type: $disk_type
		php:
		  default: ${php_default:-}
		  versions:
	EOF
		echo "Error: Failed to write output file header to $DEPLOYER_OUTPUT_FILE" >&2
		exit 1
	fi

	# Add PHP versions with extensions
	if [[ -n $php_versions ]]; then
		IFS=',' read -ra version_array <<< "$php_versions"
		for version in "${version_array[@]}"; do
			local extensions
			extensions=$(detect_php_extensions "$version")

			if ! cat >> "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
				    - version: "${version}"
				      extensions: [${extensions}]
			EOF
				echo "Error: Failed to write PHP version $version to $DEPLOYER_OUTPUT_FILE" >&2
				exit 1
			fi
		done
	else
		# No PHP versions found, write empty array
		if ! echo "    []" >> "$DEPLOYER_OUTPUT_FILE"; then
			echo "Error: Failed to write empty PHP versions to $DEPLOYER_OUTPUT_FILE" >&2
			exit 1
		fi
	fi

	# Continue with Caddy and PHP-FPM sections
	if ! cat >> "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		caddy:
		  available: $caddy_available
		  version: ${caddy_version:-unknown}
		  sites_count: ${caddy_sites:-0}
		  domains: ${caddy_domains:-}
		  uptime_seconds: ${caddy_uptime:-0}
		  active_requests: ${caddy_active_req:-0}
		  total_requests: ${caddy_total_req:-0}
		  memory_mb: ${caddy_memory:-0}
		php_fpm:
	EOF
		echo "Error: Failed to write Caddy metrics to $DEPLOYER_OUTPUT_FILE" >&2
		exit 1
	fi

	# Write PHP-FPM metrics (gathered earlier)
	if [[ $has_fpm_metrics == true ]]; then
		if ! echo "$php_fpm_yaml" >> "$DEPLOYER_OUTPUT_FILE"; then
			echo "Error: Failed to write PHP-FPM metrics to $DEPLOYER_OUTPUT_FILE" >&2
			exit 1
		fi
	else
		if ! echo "  {}" >> "$DEPLOYER_OUTPUT_FILE"; then
			echo "Error: Failed to write empty PHP-FPM section to $DEPLOYER_OUTPUT_FILE" >&2
			exit 1
		fi
	fi

	# Add ports section
	if ! echo "ports:" >> "$DEPLOYER_OUTPUT_FILE"; then
		echo "Error: Failed to write ports section header to $DEPLOYER_OUTPUT_FILE" >&2
		exit 1
	fi

	local port process has_ports=false
	while IFS=: read -r port process; do
		if ! echo "  ${port}: ${process}" >> "$DEPLOYER_OUTPUT_FILE"; then
			echo "Error: Failed to write port $port to $DEPLOYER_OUTPUT_FILE" >&2
			exit 1
		fi
		has_ports=true
	done < <(get_listening_services)

	if [[ $has_ports == false ]]; then
		if ! echo "  {}" >> "$DEPLOYER_OUTPUT_FILE"; then
			echo "Error: Failed to write empty ports section to $DEPLOYER_OUTPUT_FILE" >&2
			exit 1
		fi
	fi

	echo "→ Detecting sites configuration..."
	local sites_config_yaml="" has_sites_config=false

	while IFS=$'\t' read -r domain php_ver mode https_status; do
		has_sites_config=true
		sites_config_yaml+="  ${domain}:
    php_version: \"${php_ver}\"
    www_mode: \"${mode}\"
    https_enabled: \"${https_status}\"
"
	done < <(get_sites_config)

	if [[ $has_sites_config == true ]]; then
		if ! echo "sites_config:" >> "$DEPLOYER_OUTPUT_FILE"; then
			echo "Error: Failed to write sites_config header" >&2
			exit 1
		fi
		if ! echo "$sites_config_yaml" >> "$DEPLOYER_OUTPUT_FILE"; then
			echo "Error: Failed to write sites_config body" >&2
			exit 1
		fi
	else
		if ! echo "sites_config: {}" >> "$DEPLOYER_OUTPUT_FILE"; then
			echo "Error: Failed to write empty sites_config" >&2
			exit 1
		fi
	fi
}

main "$@"
