# PRD: Server Firewall Command

## Overview

The `server:firewall` command provides an interactive interface for managing UFW (Uncomplicated Firewall) rules on provisioned servers. Users can view all detected listening services and select which ports should be open, with the firewall automatically configured to deny all other traffic. The command prioritizes safety by ensuring SSH access is never accidentally blocked, using the SSH port configured for the server.

## Goals and Objectives

1. **Simplify firewall management** - Provide a user-friendly way to configure server firewalls without requiring UFW knowledge
2. **Prevent lockouts** - Ensure users cannot accidentally close SSH access (the server's configured SSH port)
3. **Maintain consistency** - Integrate with existing `server:info` port detection and command patterns
4. **Support automation** - Allow non-interactive usage via CLI options for scripting

## Scope

- Interactive multi-select prompt for port selection
- Detection of currently listening services/ports (TCP and UDP)
- Detection of currently open UFW ports
- UFW installation if not present
- UFW rule configuration (reset rules, deny all, allow selected ports)
- IPv4 and IPv6 support
- Confirmation summary before applying changes
- `--allow` CLI option for non-interactive mode (filtered to detected ports only)
- Safety validation preventing SSH port closure
- Shared port detection helper for DRY code with `server-info`

## User Personas

### Primary: Server Administrator

- Manages one or more servers via Deployer
- Wants to secure servers by closing unnecessary ports
- May not be familiar with UFW syntax
- Values safety and confirmation before destructive actions

### Secondary: DevOps Engineer

- Needs to script firewall configuration across multiple servers
- Requires non-interactive CLI options
- Expects consistent, predictable behavior

## Functional Requirements

### Priority 1 (Must Have)

| ID  | Requirement           | Description                                                                                  |
| --- | --------------------- | -------------------------------------------------------------------------------------------- |
| F1  | Port Detection        | Detect all listening TCP and UDP ports with associated service names using `ss` or `netstat` |
| F2  | UFW Status Detection  | Detect currently allowed ports in UFW rules                                                  |
| F3  | Multi-select Prompt   | Display ports in format `{port} ({service})` with currently open ports pre-checked           |
| F4  | SSH Port Protection   | Never display the server's SSH port in selection list; always include in allow rules         |
| F5  | Default Ports         | Pre-check ports 80 and 443 by default (in addition to currently open ports)                  |
| F6  | Confirmation Summary  | Show summary of changes (ports to open, ports to close) before applying                      |
| F7  | UFW Installation      | Install UFW if not present on the server                                                     |
| F8  | Apply Rules           | Reset all UFW rules, set default deny, allow selected ports + SSH port (atomically, safe)    |
| F9  | IPv4/IPv6 Support     | Apply rules to both IPv4 and IPv6                                                            |
| F10 | CLI Option            | Support `--allow=80,443,3306` for non-interactive execution                                  |
| F11 | Filter Invalid Ports  | `--allow` ports not in detected listening ports are silently filtered out                    |
| F12 | Shared Port Detection | Extract port detection logic into `playbooks/helpers.sh` for reuse with `server-info.sh`     |
| F13 | Current Rules Display | Show current UFW status before prompting                                                     |

## Non-Functional Requirements

| Category          | Requirement                                                                    |
| ----------------- | ------------------------------------------------------------------------------ |
| **Safety**        | SSH port must NEVER be closed; validated in both PHP command and bash playbook |
| **Safety**        | Command must abort if SSH port validation fails at any point                   |
| **Safety**        | UFW reset must allow SSH port BEFORE setting default deny policy               |
| **Idempotency**   | Running the command multiple times with same selection produces same result    |
| **Performance**   | Port detection should complete within 5 seconds                                |
| **Compatibility** | Support Ubuntu 20.04, 22.04, 24.04 and Debian 11, 12                           |
| **Consistency**   | Follow existing command patterns (BaseCommand, traits, IOService)              |
| **DRY**           | Port detection logic shared between `server-info.sh` and `server-firewall.sh`  |

## User Journeys

### Journey 1: Interactive Firewall Configuration

```
1. User runs: deployer server:firewall
2. System prompts for server selection (if multiple servers)
3. System connects to server and detects:
   - All listening ports/services (TCP and UDP)
   - Currently allowed UFW ports
4. System displays multi-select prompt:
   ┌ Select ports to allow (SSH port always allowed): ─────────────┐
   │ ◼ 80 (caddy)                                                  │
   │ ◼ 443 (caddy)                                                 │
   │ ◻ 3306 (mysql)                                                │
   │ ◻ 5432 (postgres)                                             │
   │ ◼ 6379 (redis)                                                │
   └───────────────────────────────────────────────────────────────┘
5. User toggles selections and confirms
6. System displays confirmation:
   ┌ Firewall Changes ────────────────────────────────────────────┐
   │ Ports to OPEN:  3306, 5432                                    │
   │ Ports to CLOSE: 6379                                          │
   │                                                               │
   │ SSH port will remain open.                                     │
   └───────────────────────────────────────────────────────────────┘
   Apply these changes? (yes/no)
7. User confirms
8. System resets UFW, allows SSH port first, then applies other rules
9. System displays success message
```

### Journey 2: Non-Interactive (Scripted)

```
1. User runs: deployer server:firewall --server=prod --allow=80,443,3306,9999
2. System connects to server and detects listening ports
3. System filters --allow list to only include detected ports (9999 filtered out)
4. System displays confirmation summary showing actual ports to be allowed
5. User confirms (or uses --force to skip)
6. System applies rules
```

### Journey 3: First-Time Setup (UFW Not Installed)

```
1. User runs: deployer server:firewall --server=new-server
2. System detects UFW is not installed
3. System displays: "UFW is not installed. Installing..."
4. System installs UFW
5. Flow continues as Journey 1 from step 3
```

## Technical Design

### Command Structure

```
app/Console/Server/ServerFirewallCommand.php
```

**Traits Used:**

- `ServersTrait` - Server selection
- `PlaybooksTrait` - Playbook execution
- `SSHTrait` - SSH connection management

**Services Used:**

- `IOService` - Prompts and output
- `SSHService` - Remote execution

### Playbook Structure

```
playbooks/server-firewall.sh
playbooks/helpers.sh (shared port detection function)
```

**Input Environment Variables:**

- `DEPLOYER_OUTPUT_FILE` - Path to write YAML output
- `DEPLOYER_PERMS` - Permission level (root/sudo)
- `DEPLOYER_ALLOWED_PORTS` - Comma-separated list of ports to allow
- `DEPLOYER_SSH_PORT` - The server's SSH port (from ServerDTO, e.g., 22, 2222)

**Output YAML:**

```yaml
status: success
ufw_installed: true
ufw_enabled: true
rules_applied: 5
ports_opened: [80, 443, 3306]
ports_closed: [6379]
```

### Shared Port Detection Helper

Extract `get_listening_services()` from `server-info.sh` into `helpers.sh`:

```bash
# helpers.sh - shared function
get_listening_services() {
    # Detect TCP and UDP ports
    # Returns: port:process pairs
    ss -tulnp | parse ports and processes
}
```

Update `server-info.sh` to use the shared helper instead of inline implementation.

### UFW Reset Strategy (SSH-Safe)

The playbook must reset UFW rules safely to prevent SSH lockout:

```bash
# 1. Allow SSH BEFORE any reset (idempotent)
run_cmd ufw allow "$DEPLOYER_SSH_PORT/tcp"
run_cmd ufw allow "$DEPLOYER_SSH_PORT/udp"

# 2. Reset UFW to clear all other rules
run_cmd ufw --force reset

# 3. Re-allow SSH immediately after reset
run_cmd ufw allow "$DEPLOYER_SSH_PORT/tcp"
run_cmd ufw allow "$DEPLOYER_SSH_PORT/udp"

# 4. Set default deny policy
run_cmd ufw default deny incoming
run_cmd ufw default allow outgoing

# 5. Allow user-selected ports
for port in "${ALLOWED_PORTS[@]}"; do
    run_cmd ufw allow "$port"
done

# 6. Enable UFW (if not already)
run_cmd ufw --force enable
```

### Safety Validation

**In PHP Command:**

```php
// Ensure SSH port is always in the allow list (from ServerDTO)
$sshPort = $server->port;
$allowedPorts = array_unique([$sshPort, ...$selectedPorts]);

// Filter --allow option to only detected listening ports
if ($cliAllowedPorts) {
    $allowedPorts = array_intersect($cliAllowedPorts, $detectedPorts);
    $allowedPorts = array_unique([$sshPort, ...$allowedPorts]);
}
```

**In Playbook:**

```bash
# Validate SSH port env var is set
if [[ -z "$DEPLOYER_SSH_PORT" ]]; then
    echo "FATAL: DEPLOYER_SSH_PORT environment variable must be set" >&2
    exit 1
fi

# Validate SSH port is in the list (defense in depth)
if [[ ! " ${ALLOWED_PORTS[*]} " =~ " $DEPLOYER_SSH_PORT " ]]; then
    echo "FATAL: SSH port $DEPLOYER_SSH_PORT must always be allowed" >&2
    exit 1
fi
```

## Success Metrics

| Metric                                   | Target                              |
| ---------------------------------------- | ----------------------------------- |
| Command completes without error          | 100% when valid input provided      |
| SSH port closure prevented               | 100% (zero lockouts)                |
| UFW rules match user selection           | 100% accuracy                       |
| User understands changes before applying | Confirmation shown in 100% of cases |

## Implementation Phases

### Phase 1: Shared Helper

1. Extract `get_listening_services()` into `playbooks/helpers.sh`
    - Support both TCP and UDP port detection
    - Update `server-info.sh` to use the shared helper

### Phase 2: Core Functionality

1. Create `server-firewall.sh` playbook
    - UFW installation check/install
    - UFW status detection (current rules)
    - SSH-safe UFW reset and rule application
    - SSH port validation (via `DEPLOYER_SSH_PORT` env var)

2. Create `ServerFirewallCommand.php`
    - Server selection integration
    - Parse playbook output for port/service data
    - Build multi-select options with pre-checked defaults
    - Confirmation summary display
    - SSH port validation (using `$server->port`)

### Phase 3: CLI and Polish

1. Add `--allow` CLI option
    - Parse comma-separated port list
    - Filter to detected listening ports only
    - Skip multi-select when provided

2. Add `--server` option support (already in ServersTrait)

3. Quality assurance
    - Test on Ubuntu 20.04, 22.04, 24.04
    - Test on Debian 11, 12
    - Verify IPv6 rules applied correctly
    - Verify SSH access maintained during reset

## Open Questions/Assumptions

### Assumptions

1. **UFW is the standard firewall** - All target servers use UFW (Ubuntu/Debian standard)
2. **No existing iptables rules** - If raw iptables rules exist, they may conflict with UFW
3. **Root/sudo access available** - Command requires elevated privileges
4. **SSH port from ServerDTO** - The SSH port configured when the server was added via `server:add` is used (we already connected via this port to run the playbook, so no runtime detection is needed)

## Appendix: Example Command Output

```
$ deployer server:firewall

 Select server:
 › production-web

 Connecting to production-web...
 Detecting services and firewall status...

 ┌ Select ports to allow (SSH port always allowed): ──────────────┐
 │ ◼ 80 (caddy)                                                  │
 │ ◼ 443 (caddy)                                                 │
 │ ◻ 3306 (mysql)                                                │
 │ ◻ 5432 (postgres)                                             │
 └───────────────────────────────────────────────────────────────┘

 ┌ Firewall Changes ──────────────────────────────────────────────┐
 │                                                                │
 │   Opening: 3306                                                │
 │   Closing: 5432                                                │
 │                                                                │
 │   SSH port will remain open.                                   │
 │                                                                │
 └────────────────────────────────────────────────────────────────┘

 Apply these firewall rules? (yes/no) yes

 Resetting firewall rules...
 ✓ Firewall configured successfully

   Allowed ports: [SSH], 80, 443, 3306
   Blocked: all other ports
```

### Example: Filtered --allow Output

```
$ deployer server:firewall --server=prod --allow=80,443,9999

 Connecting to prod...
 Detecting services...

 Note: Port 9999 is not a listening service and will be ignored.

 ┌ Firewall Changes ──────────────────────────────────────────────┐
 │                                                                │
 │   Allowing: [SSH], 80, 443                                     │
 │   Blocking: all other ports                                    │
 │                                                                │
 └────────────────────────────────────────────────────────────────┘

 Apply these firewall rules? (yes/no)
```
