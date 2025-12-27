#!/usr/bin/env bash

#
# Server Information
#
# Gathers distribution, hardware, installed services, and site configurations.
#
# Output:
#   distro: ubuntu
#   family: debian
#   permissions: root
#   hardware:
#     cpu_cores: 4
#     ram_mb: 8192
#     disk_type: ssd
#   php:
#     default: "8.4"
#     versions:
#       - version: "8.4"
#         extensions: [cli, fpm, mysql, curl]
#   nginx:
#     available: true
#     version: 1.24.0
#     sites_count: 2
#   ports:
#     22: sshd
#     80: nginx
#   ufw_installed: true
#   ufw_active: true
#   ufw_rules: [22/tcp, 80/tcp, 443/tcp]
#   sites_config:
#     example.com:
#       php_version: "8.4"
#       www_mode: redirect-to-root
#       https_enabled: true
#

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
# Nginx Metrics
# ----

get_nginx_metrics() {
	# Check if Nginx is available
	if ! command -v nginx > /dev/null 2>&1; then
		echo "false"  # Not installed
		return
	fi

	# Check if Nginx is running
	if ! systemctl is-active --quiet nginx 2> /dev/null; then
		echo "true\tunknown\t0\t0\t0\t0"  # Installed but not running
		return
	fi

	# Get version
	local version
	version=$(nginx -v 2>&1 | sed 's/nginx version: nginx\///' | head -n1)
	[[ -z $version ]] && version="unknown"

	# Get uptime from process
	local uptime_seconds=0
	local nginx_pid
	if nginx_pid=$(pgrep -x nginx | head -n1 2> /dev/null); then
		uptime_seconds=$(ps -p "$nginx_pid" -o etimes= 2> /dev/null | tr -d ' ')
		[[ -z $uptime_seconds ]] && uptime_seconds="0"
	fi

	# Query stub_status for metrics
	local active_connections=0
	local requests=0

	local stub_status
	stub_status=$(curl -sf --max-time 2 http://127.0.0.1:8080/nginx_status 2> /dev/null || echo "")

	if [[ -n $stub_status ]]; then
		# Parse: Active connections: N
		active_connections=$(echo "$stub_status" | grep 'Active connections:' | awk '{print $3}')
		[[ -z $active_connections ]] && active_connections="0"

		# Parse server statistics line (format: "accepts handled requests")
		local stats_line
		stats_line=$(echo "$stub_status" | sed -n '3p' | tr -s ' ')
		requests=$(echo "$stats_line" | awk '{print $3}')
		[[ -z $requests ]] && requests="0"
	fi

	# Count configured sites (excluding stub_status)
	local sites_count=0
	if [[ -d /etc/nginx/sites-enabled ]]; then
		sites_count=$(find /etc/nginx/sites-enabled -maxdepth 1 -type l ! -name 'stub_status' 2> /dev/null | wc -l)
	fi

	# Memory usage (master process RSS in MB)
	local memory_mb=0
	if [[ -n $nginx_pid ]]; then
		memory_mb=$(ps -p "$nginx_pid" -o rss= 2> /dev/null | awk '{printf "%.1f", $1/1024}')
		[[ -z $memory_mb ]] && memory_mb="0"
	fi

	# Output: available version sites_count uptime_seconds active_connections requests
	printf 'true\t%s\t%s\t%s\t%s\t%s\n' "$version" "$sites_count" "$uptime_seconds" "$active_connections" "$requests"
}

#
# PHP-FPM Metrics
# ----

get_php_fpm_metrics() {
	local php_version=$1

	# Check if PHP-FPM status endpoint is available for this version (via Nginx stub_status)
	if ! curl -sf --max-time 2 "http://127.0.0.1:8080/php${php_version}/fpm-status" > /dev/null 2>&1; then
		return 0
	fi

	# Get status in JSON format
	local status
	status=$(curl -sf --max-time 2 "http://127.0.0.1:8080/php${php_version}/fpm-status?json" 2> /dev/null || echo "{}")

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
# UFW Firewall Detection
# ----

#
# Check if UFW is installed
# Returns: "true" or "false"

check_ufw_installed() {
	if command -v ufw > /dev/null 2>&1; then
		echo "true"
	else
		echo "false"
	fi
}

#
# Check if UFW is active
# Returns: "true" or "false"

check_ufw_active() {
	local status

	status=$(run_cmd ufw status 2> /dev/null | head -n1)

	if [[ $status == "Status: active" ]]; then
		echo "true"
	else
		echo "false"
	fi
}

#
# Get UFW rules as port/proto pairs
# Output: One rule per line in format "port/proto" (e.g., "22/tcp", "80/tcp")

get_ufw_rules() {
	local line port proto

	while read -r line; do
		# Skip header lines and empty lines
		[[ -z $line ]] && continue
		[[ $line =~ ^Status: ]] && continue
		[[ $line =~ ^To ]] && continue
		[[ $line =~ ^-- ]] && continue

		# Skip IPv6 duplicates (lines ending with "(v6)")
		[[ $line =~ \(v6\)$ ]] && continue

		# Extract port/proto from the first field
		# Handles: "22/tcp", "80/tcp", "443" (no proto means both)
		if [[ $line =~ ^([0-9]+)(/[a-z]+)?[[:space:]] ]]; then
			port="${BASH_REMATCH[1]}"
			proto="${BASH_REMATCH[2]:-/tcp}"
			echo "${port}${proto}"
		fi
	done < <(run_cmd ufw status 2> /dev/null)
}

#
# Sites Configuration Detection
# ----

get_sites_config() {
	local sites_dir="/etc/nginx/sites-available"

	if [[ ! -d "$sites_dir" ]]; then
		return
	fi

	for config_file in "$sites_dir"/*; do
		[[ -f "$config_file" ]] || continue

		local filename
		filename=$(basename "$config_file")

		# Skip non-site configs
		[[ $filename == "stub_status" ]] && continue
		[[ $filename == "default" ]] && continue

		local domain=$filename
		local content
		content=$(cat "$config_file" 2> /dev/null)

		# PHP Version - extract from fastcgi_pass directive
		local php_version="unknown"
		if [[ $content =~ php([0-9]+\.[0-9]+)-fpm\.sock ]]; then
			php_version="${BASH_REMATCH[1]}"
		fi

		# WWW Mode - detect from comments or redirect patterns
		local www_mode="unknown"
		if echo "$content" | grep -q "Redirect www -> root"; then
			www_mode="redirect-to-root"
		elif echo "$content" | grep -q "Redirect root -> www"; then
			www_mode="redirect-to-www"
		fi

		# HTTPS Status - check for SSL configuration
		local https_enabled="false"
		if echo "$content" | grep -q "listen.*443.*ssl"; then
			https_enabled="true"
		fi

		# Output: domain php_version www_mode https_enabled
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

	echo "→ Checking Nginx status..."
	local nginx_info
	nginx_info=$(get_nginx_metrics)

	local nginx_available nginx_version nginx_sites nginx_uptime nginx_active nginx_requests
	IFS=$'\t' read -r nginx_available nginx_version nginx_sites nginx_uptime nginx_active nginx_requests <<< "$nginx_info"

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

	echo "→ Checking firewall status..."
	local ufw_installed ufw_active
	ufw_installed=$(check_ufw_installed)

	if [[ $ufw_installed == "true" ]]; then
		ufw_active=$(check_ufw_active)
	else
		ufw_active="false"
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

	# Continue with Nginx and PHP-FPM sections
	if ! cat >> "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		nginx:
		  available: ${nginx_available:-false}
		  version: ${nginx_version:-unknown}
		  sites_count: ${nginx_sites:-0}
		  uptime_seconds: ${nginx_uptime:-0}
		  active_connections: ${nginx_active:-0}
		  requests: ${nginx_requests:-0}
		php_fpm:
	EOF
		echo "Error: Failed to write Nginx metrics to $DEPLOYER_OUTPUT_FILE" >&2
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

	# Add UFW firewall section
	if ! cat >> "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		ufw_installed: $ufw_installed
		ufw_active: $ufw_active
		ufw_rules:
	EOF
		echo "Error: Failed to write UFW section to $DEPLOYER_OUTPUT_FILE" >&2
		exit 1
	fi

	# Write UFW rules if firewall is active
	if [[ $ufw_installed == "true" && $ufw_active == "true" ]]; then
		local rule has_rules=false
		while read -r rule; do
			[[ -z $rule ]] && continue
			if ! echo "  - ${rule}" >> "$DEPLOYER_OUTPUT_FILE"; then
				echo "Error: Failed to write UFW rule to $DEPLOYER_OUTPUT_FILE" >&2
				exit 1
			fi
			has_rules=true
		done < <(get_ufw_rules)

		if [[ $has_rules == false ]]; then
			if ! echo "  []" >> "$DEPLOYER_OUTPUT_FILE"; then
				echo "Error: Failed to write empty UFW rules to $DEPLOYER_OUTPUT_FILE" >&2
				exit 1
			fi
		fi
	else
		if ! echo "  []" >> "$DEPLOYER_OUTPUT_FILE"; then
			echo "Error: Failed to write empty UFW rules to $DEPLOYER_OUTPUT_FILE" >&2
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
