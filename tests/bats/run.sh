#!/usr/bin/env bash

set -o pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BATS_DIR="$SCRIPT_DIR"
PROJECT_ROOT="$(cd "${BATS_DIR}/../.." && pwd)"

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

# Lima VM instance name prefix
LIMA_PREFIX="deployer-test"

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

    if ! command -v bats >/dev/null 2>&1; then
        missing+=("bats")
    fi

    if ! command -v limactl >/dev/null 2>&1; then
        missing+=("lima")
    fi

    if [[ ${#missing[@]} -gt 0 ]]; then
        echo -e "${RED}Missing dependencies: ${missing[*]}${NC}"
        echo ""
        echo "Install with:"
        echo "  brew install bats-core lima"
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

lima_instance_name() {
    local distro="$1"
    echo "${LIMA_PREFIX}-${distro}"
}

lima_config_path() {
    local distro="$1"
    echo "${BATS_DIR}/lima/${distro}.yaml"
}

lima_is_running() {
    local instance="$1"
    limactl list --json 2>/dev/null | jq -e ".[] | select(.name == \"${instance}\" and .status == \"Running\")" >/dev/null 2>&1
}

lima_exists() {
    local instance="$1"
    limactl list --json 2>/dev/null | jq -e ".[] | select(.name == \"${instance}\")" >/dev/null 2>&1
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
    limactl stop "$instance" 2>/dev/null || true
    limactl delete "$instance" --force 2>/dev/null || true

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
            "echo ok" >/dev/null 2>&1; then
            echo -e "${GREEN}SSH ready on port ${port}!${NC}"
            return 0
        fi
        ((attempt++))
        sleep 1
    done

    echo -e "${RED}SSH not available on port ${port} after ${max_attempts} seconds${NC}"
    exit 1
}

stop_lima() {
    echo -e "${YELLOW}Stopping Lima VMs...${NC}"

    for distro in "${DISTROS[@]}"; do
        local instance
        instance="$(lima_instance_name "$distro")"
        if lima_exists "$instance"; then
            echo -e "${YELLOW}Stopping ${instance}...${NC}"
            limactl stop "$instance" 2>/dev/null || true
        fi
    done
}

reset_lima() {
    echo -e "${YELLOW}Resetting Lima VMs to clean state...${NC}"

    for distro in "${DISTROS[@]}"; do
        local instance
        instance="$(lima_instance_name "$distro")"
        if lima_exists "$instance"; then
            echo -e "${YELLOW}Factory resetting ${instance}...${NC}"
            limactl stop "$instance" 2>/dev/null || true
            limactl delete "$instance" --force 2>/dev/null || true
        fi
    done

    # Recreate VMs
    start_lima
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
        systemctl stop caddy 2>/dev/null || true
        rm -rf /etc/caddy/sites/* 2>/dev/null || true

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
    echo "" >/dev/tty
    echo -e "${BLUE}Select distro to test against:${NC}" >/dev/tty
    echo "" >/dev/tty

    local options=("${DISTROS[@]}" "all")
    local i=1

    for opt in "${options[@]}"; do
        if [[ "$opt" == "all" ]]; then
            echo -e "  ${i}) ${opt} (run all distros sequentially)" >/dev/tty
        else
            echo -e "  ${i}) ${opt}" >/dev/tty
        fi
        ((i++))
    done

    echo "" >/dev/tty
    read -rp "Enter choice [1-${#options[@]}]: " choice </dev/tty >/dev/tty

    if [[ ! "$choice" =~ ^[0-9]+$ ]] || [[ "$choice" -lt 1 ]] || [[ "$choice" -gt ${#options[@]} ]]; then
        echo -e "${RED}Invalid choice${NC}" >/dev/tty
        exit 1
    fi

    local selected="${options[$((choice-1))]}"
    echo "" >/dev/tty
    echo -e "${GREEN}Selected: ${selected}${NC}" >/dev/tty

    # Only this goes to stdout for capture
    echo "$selected"
}

cleanup_all_vms() {
    echo ""
    echo -e "${YELLOW}Cleaning up all VMs...${NC}"
    for distro in "${DISTROS[@]}"; do
        local instance
        instance="$(lima_instance_name "$distro")"
        limactl stop "$instance" 2>/dev/null || true
        limactl delete "$instance" --force 2>/dev/null || true
    done
    echo -e "${GREEN}All VMs removed${NC}"
}

run_tests() {
    local test_filter="${1:-}"
    local exit_code=0

    # Interactive distro selection
    local selected
    selected=$(select_distro)

    # Ensure cleanup happens on exit (success or failure)
    trap cleanup_all_vms EXIT

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
    echo "  start             Start VMs only"
    echo "  stop              Stop VMs"
    echo "  reset             Factory reset VMs to fresh state"
    echo "  clean             Clean VM state without restarting"
    echo "  ssh <distro>      SSH into a test VM"
    echo ""
    echo "Examples:"
    echo "  $0 run            # Run all tests on all distros"
    echo "  $0 run server     # Run only server.bats on all distros"
    echo "  $0 start          # Start VMs without running tests"
    echo "  $0 ssh ubuntu24   # SSH into ubuntu24 VM"
    echo "  $0 ssh debian12   # SSH into debian12 VM"
    echo ""
    echo "Available distros: ${DISTROS[*]}"
    echo ""
    echo "Environment:"
    echo "  BATS_DEBUG=1      # Enable verbose debug output in tests"
    echo ""
    echo "Output:"
    echo "  By default, only test names and failure details are shown."
    echo "  Use BATS_DEBUG=1 for verbose output on all tests."
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
        start_lima
        echo ""
        echo -e "${GREEN}VMs running.${NC}"
        echo "  Run tests:  $0 run"
        echo "  SSH in:     $0 ssh <distro>"
        echo "  Stop:       $0 stop"
        ;;
    stop)
        stop_lima
        ;;
    reset)
        setup_keys
        setup_inventory
        reset_lima
        ;;
    clean)
        clean_all_vms
        echo -e "${GREEN}All VMs cleaned${NC}"
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
    help|--help|-h)
        show_usage
        ;;
    *)
        echo -e "${RED}Unknown command: $1${NC}"
        show_usage
        exit 1
        ;;
esac
