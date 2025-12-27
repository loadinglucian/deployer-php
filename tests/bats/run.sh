#!/usr/bin/env bash

set -o pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BATS_DIR="$SCRIPT_DIR"
PROJECT_ROOT="$(cd "${BATS_DIR}/../.." && pwd)"

# Load .env file if it exists (for AWS/DO credentials)
if [[ -f "${PROJECT_ROOT}/.env" ]]; then
	set -a
	# shellcheck source=/dev/null
	source "${PROJECT_ROOT}/.env"
	set +a
fi

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Test server configuration
TEST_SERVER_HOST="127.0.0.1"
TEST_KEY="${BATS_DIR}/fixtures/keys/id_test"

# Available distros and their SSH ports
# Add new distros here - tests will run against all of them
declare -A DISTRO_PORTS=(
	["ubuntu24"]="2222"
	["ubuntu25"]="2224"
	["debian12"]="2223"
	["debian13"]="2225"
)

# Get ordered list of distro names
DISTROS=("ubuntu24" "ubuntu25" "debian12" "debian13")

# Source shared Lima core functions (LIMA_PREFIX, lima_instance_name, lima_is_running, lima_exists)
# shellcheck source=lib/lima-core.bash
source "${BATS_DIR}/lib/lima-core.bash"

# Track which distros this runner is responsible for (set during run)
RUNNER_DISTROS=()

# ----
# Functions
# ----

print_header() {
	echo ""
	echo "=================================="
	echo " Deployer BATS Integration Tests"
	echo "=================================="
	echo ""
}

print_distro_header() {
	local distro="$1"
	echo ""
	echo -e "${BLUE}──────────────────────────────────${NC}"
	echo -e "${BLUE} Testing on: ${distro}${NC}"
	echo -e "${BLUE}──────────────────────────────────${NC}"
	echo ""
}

check_dependencies() {
	local missing=()

	if ! command -v bats > /dev/null 2>&1; then
		missing+=("bats")
	fi

	if ! command -v limactl > /dev/null 2>&1; then
		missing+=("lima")
	fi

	if ! command -v jq > /dev/null 2>&1; then
		missing+=("jq")
	fi

	if [[ ${#missing[@]} -gt 0 ]]; then
		echo -e "${RED}Missing dependencies: ${missing[*]}${NC}"
		echo ""
		echo "Install with:"
		echo "  brew install bats-core lima jq"
		exit 1
	fi
}

setup_keys() {
	local keys_dir="${BATS_DIR}/fixtures/keys"
	local private_key="${keys_dir}/id_test"

	if [[ ! -f "$private_key" ]]; then
		echo -e "${YELLOW}Generating test SSH keys...${NC}"
		mkdir -p "$keys_dir"
		ssh-keygen -t ed25519 -f "$private_key" -N "" -C "deployer-bats-test"
		chmod 600 "$private_key"
		chmod 644 "${private_key}.pub"
		echo -e "${GREEN}SSH keys generated${NC}"
	fi
}

setup_inventory() {
	local inventory_dir="${BATS_DIR}/fixtures/inventory"
	local inventory_file="${inventory_dir}/test-deployer.yml"

	mkdir -p "$inventory_dir"

	cat > "$inventory_file" << 'EOF'
servers: []
sites: []
EOF
}

#
# Lima Lifecycle
# ----
# Core functions (lima_instance_name, lima_is_running, lima_exists) are sourced from lib/lima-core.bash

lima_config_path() {
	local distro="$1"
	echo "${BATS_DIR}/lima/${distro}.yaml"
}

start_lima_instance() {
	local distro="$1"
	local instance
	instance="$(lima_instance_name "$distro")"
	local config
	config="$(lima_config_path "$distro")"
	local port="${DISTRO_PORTS[$distro]}"

	# Always attempt cleanup for a clean slate (ignore errors if doesn't exist)
	echo -e "${YELLOW}Cleaning up any existing VM ${instance}...${NC}"
	limactl stop "$instance" 2> /dev/null || true
	limactl delete "$instance" --force 2> /dev/null || true

	echo -e "${YELLOW}Creating VM ${instance} from ${distro}.yaml...${NC}"
	limactl start --name="$instance" --tty=false "$config"

	# Copy SSH public key to the VM for root access
	copy_ssh_key "$instance" "$port"
}

copy_ssh_key() {
	local instance="$1"
	local port="$2"
	local pub_key="${TEST_KEY}.pub"

	echo -e "${YELLOW}Copying SSH key to ${instance}...${NC}"

	# Use limactl shell to copy the key (runs as lima user with sudo)
	local pub_key_content
	pub_key_content="$(cat "$pub_key")"

	limactl shell "$instance" sudo bash -c "
        mkdir -p /root/.ssh
        chmod 700 /root/.ssh
        echo '${pub_key_content}' >> /root/.ssh/authorized_keys
        chmod 600 /root/.ssh/authorized_keys
        # Also for deployer user
        mkdir -p /home/deployer/.ssh
        echo '${pub_key_content}' >> /home/deployer/.ssh/authorized_keys
        chown -R deployer:deployer /home/deployer/.ssh
        chmod 700 /home/deployer/.ssh
        chmod 600 /home/deployer/.ssh/authorized_keys
    "
}

start_lima() {
	echo -e "${YELLOW}Starting Lima VMs...${NC}"

	for distro in "${DISTROS[@]}"; do
		start_lima_instance "$distro"
	done

	echo -e "${YELLOW}Waiting for SSH on all VMs...${NC}"
	for distro in "${DISTROS[@]}"; do
		wait_for_ssh "${DISTRO_PORTS[$distro]}"
	done
}

wait_for_ssh() {
	local port="$1"
	local max_attempts=60
	local attempt=0

	while [[ $attempt -lt $max_attempts ]]; do
		if ssh -i "$TEST_KEY" \
			-o StrictHostKeyChecking=no \
			-o UserKnownHostsFile=/dev/null \
			-o ConnectTimeout=2 \
			-o LogLevel=ERROR \
			-p "$port" \
			"root@${TEST_SERVER_HOST}" \
			"echo ok" > /dev/null 2>&1; then
			echo -e "${GREEN}SSH ready on port ${port}!${NC}"
			return 0
		fi
		((attempt++))
		sleep 1
	done

	echo -e "${RED}SSH not available on port ${port} after ${max_attempts} seconds${NC}"
	exit 1
}

stop_lima_instance() {
	local distro="$1"
	local instance
	instance="$(lima_instance_name "$distro")"

	if lima_exists "$instance"; then
		echo -e "${YELLOW}Stopping ${instance}...${NC}"
		limactl stop "$instance" 2> /dev/null || true
	fi
}

stop_lima() {
	local target_distro="${1:-}"

	if [[ -n "$target_distro" ]]; then
		# Stop single distro
		echo -e "${YELLOW}Stopping Lima VM for ${target_distro}...${NC}"
		stop_lima_instance "$target_distro"
	else
		# Stop all distros
		echo -e "${YELLOW}Stopping all Lima VMs...${NC}"
		for distro in "${DISTROS[@]}"; do
			stop_lima_instance "$distro"
		done
	fi
}

reset_lima_instance() {
	local distro="$1"
	local instance
	instance="$(lima_instance_name "$distro")"

	if lima_exists "$instance"; then
		echo -e "${YELLOW}Factory resetting ${instance}...${NC}"
		limactl stop "$instance" 2> /dev/null || true
		limactl delete "$instance" --force 2> /dev/null || true
	fi

	# Recreate VM
	start_lima_instance "$distro"
	wait_for_ssh "${DISTRO_PORTS[$distro]}"
}

reset_lima() {
	local target_distro="${1:-}"

	if [[ -n "$target_distro" ]]; then
		# Reset single distro
		echo -e "${YELLOW}Resetting Lima VM for ${target_distro}...${NC}"
		reset_lima_instance "$target_distro"
	else
		# Reset all distros
		echo -e "${YELLOW}Resetting all Lima VMs to clean state...${NC}"
		for distro in "${DISTROS[@]}"; do
			local instance
			instance="$(lima_instance_name "$distro")"
			if lima_exists "$instance"; then
				echo -e "${YELLOW}Factory resetting ${instance}...${NC}"
				limactl stop "$instance" 2> /dev/null || true
				limactl delete "$instance" --force 2> /dev/null || true
			fi
		done

		# Recreate all VMs
		start_lima
	fi
}

clean_vm() {
	local distro="$1"
	local port="${DISTRO_PORTS[$distro]}"

	echo -e "${YELLOW}Cleaning VM state for ${distro}...${NC}"

	# Clean up deployer-created artifacts without restarting VM
	ssh -i "$TEST_KEY" \
		-o StrictHostKeyChecking=no \
		-o UserKnownHostsFile=/dev/null \
		-o LogLevel=ERROR \
		-p "$port" \
		"root@${TEST_SERVER_HOST}" \
		"
        # Remove deployer-created files and directories
        rm -rf /home/deployer/sites/* 2>/dev/null || true

        # Stop and clean up any installed services
        systemctl stop nginx 2>/dev/null || true
        rm -rf /etc/nginx/sites-enabled/* 2>/dev/null || true

        # Clean up supervisor programs
        rm -rf /etc/supervisor/conf.d/deployer-* 2>/dev/null || true
        supervisorctl reread 2>/dev/null || true

        # Clean up cron jobs
        crontab -r 2>/dev/null || true
        "
}

clean_all_vms() {
	for distro in "${DISTROS[@]}"; do
		clean_vm "$distro"
	done
}

#
# Test Execution
# ----

# API-only tests (no VM required)
API_ONLY_TESTS=("pro-aws" "pro-do")

is_api_only_test() {
	local test_filter="$1"
	for api_test in "${API_ONLY_TESTS[@]}"; do
		if [[ "$test_filter" == "$api_test" ]]; then
			return 0
		fi
	done
	return 1
}

run_api_tests() {
	local test_filter="$1"
	local exit_code=0
	local bats_opts=("--print-output-on-failure")

	echo ""
	echo -e "${BLUE}Running API tests: ${test_filter}${NC}"
	echo -e "${BLUE}(No VM required)${NC}"
	echo ""

	if [[ -f "${BATS_DIR}/${test_filter}.bats" ]]; then
		bats "${bats_opts[@]}" "${BATS_DIR}/${test_filter}.bats" || exit_code=$?
	else
		echo -e "${RED}Test file not found: ${test_filter}.bats${NC}"
		exit_code=1
	fi

	return $exit_code
}

run_tests_for_distro() {
	local distro="$1"
	local test_filter="${2:-}"
	local exit_code=0

	# Clean VM state before running tests
	clean_vm "$distro"

	print_distro_header "$distro"
	export BATS_DISTRO="$distro"

	# Use minimal output - only show details on failure
	# BATS default behavior shows test names with ✓/✗ and full output on failures
	# Use BATS_DEBUG=1 to enable debug_output() calls in tests for verbose mode
	local bats_opts=("--print-output-on-failure")

	if [[ -n "$test_filter" ]]; then
		# Run specific test file
		if [[ -f "${BATS_DIR}/${test_filter}.bats" ]]; then
			bats "${bats_opts[@]}" "${BATS_DIR}/${test_filter}.bats" || exit_code=$?
		else
			echo -e "${RED}Test file not found: ${test_filter}.bats${NC}"
			exit_code=1
		fi
	else
		# Run all tests
		local test_files=("${BATS_DIR}"/*.bats)
		if [[ -e "${test_files[0]}" ]]; then
			bats "${bats_opts[@]}" "${BATS_DIR}"/*.bats || exit_code=$?
		else
			echo -e "${YELLOW}No test files found in ${BATS_DIR}${NC}"
			exit_code=0
		fi
	fi

	return $exit_code
}

select_distro() {
	echo "" > /dev/tty
	echo -e "${BLUE}Select distro to test against:${NC}" > /dev/tty
	echo "" > /dev/tty

	local options=("${DISTROS[@]}" "all")
	local i=1

	for opt in "${options[@]}"; do
		if [[ "$opt" == "all" ]]; then
			echo -e "  ${i}) ${opt} (run all distros sequentially)" > /dev/tty
		else
			echo -e "  ${i}) ${opt}" > /dev/tty
		fi
		((i++))
	done

	echo "" > /dev/tty
	read -rp "Enter choice [1-${#options[@]}]: " choice < /dev/tty > /dev/tty

	if [[ ! "$choice" =~ ^[0-9]+$ ]] || [[ "$choice" -lt 1 ]] || [[ "$choice" -gt ${#options[@]} ]]; then
		echo -e "${RED}Invalid choice${NC}" > /dev/tty
		exit 1
	fi

	local selected="${options[$((choice - 1))]}"
	echo "" > /dev/tty
	echo -e "${GREEN}Selected: ${selected}${NC}" > /dev/tty

	# Only this goes to stdout for capture
	echo "$selected"
}

cleanup_runner_vms() {
	if [[ ${#RUNNER_DISTROS[@]} -eq 0 ]]; then
		return 0
	fi

	echo ""
	echo -e "${YELLOW}Cleaning up VMs started by this runner...${NC}"
	for distro in "${RUNNER_DISTROS[@]}"; do
		local instance
		instance="$(lima_instance_name "$distro")"
		echo -e "${YELLOW}Stopping ${instance}...${NC}"
		limactl stop "$instance" 2> /dev/null || true
		limactl delete "$instance" --force 2> /dev/null || true
	done
	echo -e "${GREEN}Runner VMs removed${NC}"
}

run_tests() {
	local test_filter="${1:-}"
	local exit_code=0

	# API-only tests don't need VMs
	if [[ -n "$test_filter" ]] && is_api_only_test "$test_filter"; then
		run_api_tests "$test_filter"
		return $?
	fi

	# Interactive distro selection
	local selected
	selected=$(select_distro)

	# Track which distros this runner is responsible for
	if [[ "$selected" == "all" ]]; then
		RUNNER_DISTROS=("${DISTROS[@]}")
	else
		RUNNER_DISTROS=("$selected")
	fi

	# Ensure cleanup happens on exit (success or failure) - only cleans this runner's VMs
	trap cleanup_runner_vms EXIT

	if [[ "$selected" == "all" ]]; then
		# Start all VMs, then run tests on each
		start_lima
		for distro in "${DISTROS[@]}"; do
			run_tests_for_distro "$distro" "$test_filter" || exit_code=$?
		done
	else
		# Start only the selected VM
		start_lima_instance "$selected"
		wait_for_ssh "${DISTRO_PORTS[$selected]}"
		run_tests_for_distro "$selected" "$test_filter" || exit_code=$?
	fi

	return $exit_code
}

show_usage() {
	echo "Usage: $0 [command] [options]"
	echo ""
	echo "Commands:"
	echo "  run [filter]      Run tests (optionally filtered by test file name)"
	echo "  start [distro]    Start VMs (all if no distro specified)"
	echo "  stop [distro]     Stop VMs (all if no distro specified)"
	echo "  reset [distro]    Factory reset VMs (all if no distro specified)"
	echo "  clean [distro]    Clean VM state without restarting"
	echo "  ssh <distro>      SSH into a test VM"
	echo ""
	echo "Examples:"
	echo "  $0 run            # Run all tests (interactive distro selection)"
	echo "  $0 run server     # Run only server.bats"
	echo "  $0 run pro-aws    # Run AWS API tests (no VM needed)"
	echo "  $0 run pro-do     # Run DigitalOcean API tests (no VM needed)"
	echo "  $0 start          # Start all VMs"
	echo "  $0 start ubuntu24 # Start only ubuntu24 VM"
	echo "  $0 stop debian12  # Stop only debian12 VM"
	echo "  $0 ssh ubuntu24   # SSH into ubuntu24 VM"
	echo ""
	echo "Available distros: ${DISTROS[*]}"
	echo "API-only tests (no VM): ${API_ONLY_TESTS[*]}"
	echo ""
	echo "Environment:"
	echo "  BATS_DEBUG=1      # Enable verbose debug output in tests"
	echo ""
	echo "Parallel execution:"
	echo "  Each command operates only on specified distro(s), allowing"
	echo "  multiple runners to operate on different distros in parallel."
}

# ----
# Main
# ----

cd "$PROJECT_ROOT" || exit 1

print_header
check_dependencies

case "${1:-run}" in
	run)
		setup_keys
		setup_inventory
		run_tests "${2:-}"
		exit_code=$?
		exit $exit_code
		;;
	start)
		setup_keys
		setup_inventory
		distro="${2:-}"
		if [[ -n "$distro" ]]; then
			if [[ -z "${DISTRO_PORTS[$distro]:-}" ]]; then
				echo -e "${RED}Unknown distro: ${distro}${NC}"
				echo "Available distros: ${DISTROS[*]}"
				exit 1
			fi
			start_lima_instance "$distro"
			wait_for_ssh "${DISTRO_PORTS[$distro]}"
			echo ""
			echo -e "${GREEN}VM ${distro} running.${NC}"
		else
			start_lima
			echo ""
			echo -e "${GREEN}All VMs running.${NC}"
		fi
		echo "  Run tests:  $0 run"
		echo "  SSH in:     $0 ssh <distro>"
		echo "  Stop:       $0 stop [distro]"
		;;
	stop)
		distro="${2:-}"
		if [[ -n "$distro" && -z "${DISTRO_PORTS[$distro]:-}" ]]; then
			echo -e "${RED}Unknown distro: ${distro}${NC}"
			echo "Available distros: ${DISTROS[*]}"
			exit 1
		fi
		stop_lima "$distro"
		;;
	reset)
		setup_keys
		setup_inventory
		distro="${2:-}"
		if [[ -n "$distro" && -z "${DISTRO_PORTS[$distro]:-}" ]]; then
			echo -e "${RED}Unknown distro: ${distro}${NC}"
			echo "Available distros: ${DISTROS[*]}"
			exit 1
		fi
		reset_lima "$distro"
		;;
	clean)
		distro="${2:-}"
		if [[ -n "$distro" ]]; then
			if [[ -z "${DISTRO_PORTS[$distro]:-}" ]]; then
				echo -e "${RED}Unknown distro: ${distro}${NC}"
				echo "Available distros: ${DISTROS[*]}"
				exit 1
			fi
			clean_vm "$distro"
			echo -e "${GREEN}VM ${distro} cleaned${NC}"
		else
			clean_all_vms
			echo -e "${GREEN}All VMs cleaned${NC}"
		fi
		;;
	ssh)
		distro="${2:-}"
		if [[ -z "$distro" ]]; then
			echo -e "${RED}Usage: $0 ssh <distro>${NC}"
			echo "Available distros: ${DISTROS[*]}"
			exit 1
		fi
		if [[ -z "${DISTRO_PORTS[$distro]:-}" ]]; then
			echo -e "${RED}Unknown distro: ${distro}${NC}"
			echo "Available distros: ${DISTROS[*]}"
			exit 1
		fi
		ssh -i "$TEST_KEY" \
			-o StrictHostKeyChecking=no \
			-o UserKnownHostsFile=/dev/null \
			-p "${DISTRO_PORTS[$distro]}" \
			"root@${TEST_SERVER_HOST}"
		;;
	help | --help | -h)
		show_usage
		;;
	*)
		echo -e "${RED}Unknown command: $1${NC}"
		show_usage
		exit 1
		;;
esac
