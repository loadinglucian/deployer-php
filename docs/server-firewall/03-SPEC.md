# Technical Specification - Server Firewall Command

## Context

### From PRD

The `server:firewall` command provides an interactive interface for managing UFW (Uncomplicated Firewall) rules on provisioned servers. Users can view all detected listening services and select which ports should be open, with the firewall automatically configured to deny all other traffic. The command prioritizes safety by ensuring SSH access is never accidentally blocked, using the SSH port configured for the server.

**Key Safety Requirement:** SSH access must never be accidentally blocked. The command uses the SSH port configured for the server (from ServerDTO) and ensures it's always included in allow rules.

**Target Users:** Server administrators who want simplified firewall management, and DevOps engineers who need scriptable firewall configuration.

**Technical Stack:** PHP Command (ServerFirewallCommand.php) + Bash Playbook (server-firewall.sh) + Shared Helper (helpers.sh)

### From Features

13 must-have features across 3 phases:

- **Phase 1:** Shared port detection (F12), port detection (F1), UFW status detection (F2)
- **Phase 2:** Multi-select prompt (F3), SSH protection (F4), default ports (F5), current rules display (F13), confirmation (F6), UFW installation (F7), apply rules (F8), IPv4/IPv6 (F9)
- **Phase 3:** CLI option (F10), filter invalid ports (F11)

Critical path: `F12 -> F1 -> F3 -> F6 -> F7 -> F8`

### Specification Summary

- **Components:** ServerFirewallCommand.php (orchestration), server-firewall.sh (detect/apply modes), helpers.sh (shared `get_listening_services()`)
- **Two-phase playbook:** Detection mode returns ports/UFW state, Apply mode configures firewall
- **SSH safety:** Defense-in-depth validation in both PHP and bash; SSH port from ServerDTO always included
- **Interactive flow:** Multi-select with pre-checked defaults (80, 443, current UFW rules) -> confirmation summary -> apply
- **Non-interactive:** `--allow` option filters to detected ports only; `--force` skips confirmation

---

## Overview

Two-mode playbook architecture: PHP command orchestrates server selection and user interaction, delegates detection and rule application to bash playbook with YAML output.

**Components:**

| Component                    | Type     | Purpose                                    |
| ---------------------------- | -------- | ------------------------------------------ |
| ServerFirewallCommand.php    | Command  | Orchestrate UI, validation, playbook calls |
| server-firewall.sh           | Playbook | Detect UFW/ports, apply rules              |
| helpers.sh                   | Library  | Shared `get_listening_services()` function |

**Architecture:**

```text
┌─────────────────────────────────────────────────────────────────────┐
│                     ServerFirewallCommand.php                       │
├─────────────────────────────────────────────────────────────────────┤
│  1. selectServer() -> ServerDTO ($server->port = SSH port)          │
│  2. executePlaybook(mode=detect) -> ports, UFW state                │
│  3. filterSshPort() + promptPortSelection() or parseAllowOption()   │
│  4. displayConfirmation() -> user approves                          │
│  5. executePlaybook(mode=apply, DEPLOYER_SSH_PORT, ALLOWED_PORTS)   │
└─────────────────────────────────────────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│                      server-firewall.sh                             │
├─────────────────────────────────────────────────────────────────────┤
│  detect mode:                                                       │
│    - check_ufw_installed(), check_ufw_active(), get_ufw_rules()     │
│    - get_listening_services() [from helpers.sh]                     │
│    -> YAML: ufw_installed, ufw_active, ufw_rules[], ports{}         │
│                                                                     │
│  apply mode:                                                        │
│    - validate_ssh_port() [defense in depth]                         │
│    - install_ufw_if_missing()                                       │
│    - allow SSH -> reset -> allow SSH -> policies -> ports -> enable │
│    -> YAML: status, rules_applied, ports_allowed[]                  │
└─────────────────────────────────────────────────────────────────────┘
```

**Design Decisions:**

- **Two-mode playbook:** Single playbook with detect/apply modes reduces file count, enables shared context
- **SSH port from ServerDTO:** No runtime detection needed; we already connected via this port
- **Filtered --allow:** CLI ports filtered to detected services prevents opening non-listening ports
- **Pre-reset SSH allow:** Idempotent SSH allow before reset prevents lockout if UFW is active
- **Assume UFW IPv6 defaults:** UFW applies IPv6 rules automatically; no /etc/default/ufw verification needed

---

## Feature Specifications

### F12: Shared Port Detection

| Attribute      | Value                |
| -------------- | -------------------- |
| Source         | 02-FEATURES.md §F12  |
| Components     | helpers.sh           |
| New Files      | None (exists)        |
| Modified Files | helpers.sh           |

**Interface Contract:**

| Method/Function         | Input | Output                         | Errors           |
| ----------------------- | ----- | ------------------------------ | ---------------- |
| get_listening_services  | None  | `port:process\n` pairs, sorted | None (silent)    |

**Implementation Notes:**

Function already exists in helpers.sh with correct implementation using `ss -tulnp` with netstat fallback.

**Verification:**

- [ ] Function exists in helpers.sh
- [ ] Returns sorted, deduplicated port:process pairs
- [ ] server-info.sh uses get_listening_services
- [ ] server-firewall.sh uses get_listening_services

---

### F1: Port Detection

| Attribute      | Value                |
| -------------- | -------------------- |
| Source         | 02-FEATURES.md §F1   |
| Components     | server-firewall.sh   |
| New Files      | None                 |
| Modified Files | None (uses F12)      |

**Playbook Contract:**

| Variable             | Type   | Required | Description                  |
| -------------------- | ------ | -------- | ---------------------------- |
| DEPLOYER_MODE        | string | Yes      | Must be "detect"             |
| DEPLOYER_PERMS       | string | Yes      | "root" or "sudo"             |
| DEPLOYER_OUTPUT_FILE | string | Yes      | Path for YAML output         |

Output (detect mode):

```yaml
status: success
ports:
  22: sshd
  80: caddy
  443: caddy
  3306: mysql
```

**Verification:**

- [ ] TCP listening ports detected
- [ ] UDP listening ports detected
- [ ] Process names associated with ports
- [ ] Completes within 5 seconds

---

### F2: UFW Status Detection

| Attribute      | Value                |
| -------------- | -------------------- |
| Source         | 02-FEATURES.md §F2   |
| Components     | server-firewall.sh   |
| New Files      | None                 |
| Modified Files | None                 |

**Interface Contract:**

| Method/Function     | Input | Output           | Errors        |
| ------------------- | ----- | ---------------- | ------------- |
| check_ufw_installed | None  | "true" or "false"| None          |
| check_ufw_active    | None  | "true" or "false"| None          |
| get_ufw_rules       | None  | port/proto lines | None (silent) |

**Playbook Contract:**

Output (detect mode):

```yaml
ufw_installed: true
ufw_active: true
ufw_rules:
  - 22/tcp
  - 80/tcp
  - 443/tcp
```

**Edge Cases:**

| Scenario           | Behavior                        |
| ------------------ | ------------------------------- |
| UFW not installed  | ufw_installed: false            |
| UFW inactive       | ufw_active: false, rules: []    |
| No rules defined   | ufw_rules: []                   |

**Verification:**

- [ ] Parses `ufw status` output correctly
- [ ] Handles UFW not installed
- [ ] Handles UFW inactive
- [ ] Extracts port/protocol from rule lines

---

### F13: Current Rules Display

| Attribute      | Value                    |
| -------------- | ------------------------ |
| Source         | 02-FEATURES.md §F13      |
| Components     | ServerFirewallCommand    |
| New Files      | None                     |
| Modified Files | None                     |

**Interface Contract:**

| Method/Function      | Input                       | Output | Errors |
| -------------------- | --------------------------- | ------ | ------ |
| displayCurrentStatus | bool, bool, array\<string\> | void   | None   |

**Data Structures:**

| Name   | Type           | Fields                        | Purpose                  |
| ------ | -------------- | ----------------------------- | ------------------------ |
| status | Display output | UFW status, rules list        | Show current firewall state |

**Integration Points:**

- IOService: displayDeets() for formatted output

**Error Taxonomy:**

| Condition       | Message                    | Behavior       |
| --------------- | -------------------------- | -------------- |
| Not installed   | "Not installed" (yellow)   | Display only   |
| Inactive        | "Inactive" (yellow)        | Display only   |
| Active no rules | "Active" (green)           | Display only   |

**Verification:**

- [ ] Shows "Not installed" when UFW missing
- [ ] Shows "Inactive" when UFW disabled
- [ ] Shows "Active" with rules when configured

---

### F3: Multi-select Prompt

| Attribute      | Value                    |
| -------------- | ------------------------ |
| Source         | 02-FEATURES.md §F3       |
| Components     | ServerFirewallCommand    |
| New Files      | None                     |
| Modified Files | None                     |

**Interface Contract:**

| Method/Function       | Input                                    | Output       | Errors |
| --------------------- | ---------------------------------------- | ------------ | ------ |
| promptPortSelection   | array\<int,string\>, array\<int\>        | array\<int\> | None   |

**Data Structures:**

| Name    | Type               | Fields            | Purpose                    |
| ------- | ------------------ | ----------------- | -------------------------- |
| options | array\<int,string\>| port => "port (process)" | Display format    |

**Integration Points:**

- IOService: promptMultiselect() for user selection

**Verification:**

- [ ] Displays format: "80 (caddy)"
- [ ] SSH port excluded from options
- [ ] Pre-checks default ports (80, 443) if detected
- [ ] Pre-checks current UFW ports if detected
- [ ] Returns selected port numbers as integers

---

### F4: SSH Port Protection

| Attribute      | Value                    |
| -------------- | ------------------------ |
| Source         | 02-FEATURES.md §F4       |
| Components     | ServerFirewallCommand, server-firewall.sh |
| New Files      | None                     |
| Modified Files | None                     |

**Interface Contract:**

| Method/Function  | Input                       | Output             | Errors                         |
| ---------------- | --------------------------- | ------------------ | ------------------------------ |
| filterSshPort    | array\<int,string\>, int    | array\<int,string\>| None                           |
| validate_ssh_port| env vars                    | void               | Exit 1 if SSH port not allowed |

**Playbook Contract:**

| Variable           | Type   | Required | Description                   |
| ------------------ | ------ | -------- | ----------------------------- |
| DEPLOYER_SSH_PORT  | string | Yes      | Server's SSH port from ServerDTO |

**Error Taxonomy:**

| Condition            | Message                                      | Behavior |
| -------------------- | -------------------------------------------- | -------- |
| SSH port env not set | "FATAL: DEPLOYER_SSH_PORT must be set"       | Exit 1   |
| SSH port not allowed | "FATAL: SSH port X must always be allowed"   | Exit 1   |

**Security Constraints:**

- SSH port never appears in multi-select list (filtered by filterSshPort)
- SSH port always prepended to allowed ports in PHP before playbook call
- Playbook validates SSH port in allowed list (defense in depth)
- SSH allowed before and after UFW reset

**Verification:**

- [ ] SSH port hidden from selection
- [ ] SSH port always in final allow list
- [ ] Playbook aborts if SSH port validation fails
- [ ] Uses $server->port from ServerDTO

---

### F5: Default Ports

| Attribute      | Value                    |
| -------------- | ------------------------ |
| Source         | 02-FEATURES.md §F5       |
| Components     | ServerFirewallCommand    |
| New Files      | None                     |
| Modified Files | None                     |

**Interface Contract:**

| Method/Function  | Input                       | Output       | Errors |
| ---------------- | --------------------------- | ------------ | ------ |
| getDefaultPorts  | array\<int\>, array\<int\>  | array\<int\> | None   |

**Data Structures:**

| Name          | Type        | Fields   | Purpose                     |
| ------------- | ----------- | -------- | --------------------------- |
| DEFAULT_PORTS | const array | [80,443] | Ports to pre-check by default |

**Verification:**

- [ ] Port 80 pre-checked if detected
- [ ] Port 443 pre-checked if detected
- [ ] Only pre-checks detected listening ports
- [ ] Merged with current UFW rules for defaults

---

### F6: Confirmation Summary

| Attribute      | Value                    |
| -------------- | ------------------------ |
| Source         | 02-FEATURES.md §F6       |
| Components     | ServerFirewallCommand    |
| New Files      | None                     |
| Modified Files | None                     |

**Interface Contract:**

| Method/Function     | Input                                    | Output | Errors |
| ------------------- | ---------------------------------------- | ------ | ------ |
| displayConfirmation | array\<int\>, array\<int\>, int, bool    | bool   | None   |

**Data Structures:**

| Name    | Type  | Fields                              | Purpose           |
| ------- | ----- | ----------------------------------- | ----------------- |
| changes | array | Opening, Closing, SSH, Status       | Summary display   |

**Integration Points:**

- IOService: displayDeets(), promptConfirm()

**Edge Cases:**

| Scenario    | Behavior                              |
| ----------- | ------------------------------------- |
| No changes  | Display "Status: No changes needed"   |
| Force flag  | Skip confirmation, return true        |

**Verification:**

- [ ] Shows ports to open
- [ ] Shows ports to close
- [ ] Shows "SSH port X will remain open"
- [ ] Requires yes/no confirmation
- [ ] --force skips confirmation

---

### F7: UFW Installation

| Attribute      | Value                    |
| -------------- | ------------------------ |
| Source         | 02-FEATURES.md §F7       |
| Components     | server-firewall.sh       |
| New Files      | None                     |
| Modified Files | None                     |

**Interface Contract:**

| Method/Function       | Input | Output | Errors                     |
| --------------------- | ----- | ------ | -------------------------- |
| install_ufw_if_missing| None  | void   | Exit via fail() on error   |

**Integration Points:**

- helpers.sh: wait_for_dpkg_lock(), apt_get_with_retry()

**Error Taxonomy:**

| Condition          | Message                            | Behavior |
| ------------------ | ---------------------------------- | -------- |
| Lock timeout       | "Timeout waiting for dpkg lock"    | Exit 1   |
| Install failure    | "Failed to install UFW"            | Exit 1   |

**Verification:**

- [ ] Detects if UFW installed via `command -v ufw`
- [ ] Installs via apt-get if missing
- [ ] Displays "Installing UFW..." message
- [ ] Handles dpkg lock contention

---

### F8: Apply Rules

| Attribute      | Value                    |
| -------------- | ------------------------ |
| Source         | 02-FEATURES.md §F8       |
| Components     | server-firewall.sh       |
| New Files      | None                     |
| Modified Files | None                     |

**Interface Contract:**

| Method/Function      | Input | Output | Errors                    |
| -------------------- | ----- | ------ | ------------------------- |
| allow_ssh_port       | None  | void   | Silent (idempotent)       |
| reset_ufw            | None  | void   | Exit via fail()           |
| set_default_policies | None  | void   | Exit via fail()           |
| allow_selected_ports | None  | void   | Exit via fail()           |
| enable_ufw           | None  | void   | Exit via fail()           |

**Playbook Contract:**

| Variable               | Type   | Required | Description                    |
| ---------------------- | ------ | -------- | ------------------------------ |
| DEPLOYER_MODE          | string | Yes      | Must be "apply"                |
| DEPLOYER_SSH_PORT      | string | Yes      | Server's SSH port              |
| DEPLOYER_ALLOWED_PORTS | string | Yes      | Comma-separated allowed ports  |

Output (apply mode):

```yaml
status: success
ufw_installed: true
ufw_enabled: true
rules_applied: 4
ports_allowed:
  - 22
  - 80
  - 443
```

**Error Taxonomy:**

| Condition       | Message                          | Behavior |
| --------------- | -------------------------------- | -------- |
| Reset fails     | "Failed to reset UFW"            | Exit 1   |
| Policy fails    | "Failed to set incoming policy"  | Exit 1   |
| Allow fails     | "Failed to allow port X"         | Exit 1   |
| Enable fails    | "Failed to enable UFW"           | Exit 1   |

**Security Constraints:**

Order is critical for SSH safety:

1. `ufw allow $SSH_PORT/tcp` (before reset)
2. `ufw --force reset`
3. `ufw allow $SSH_PORT/tcp` (after reset)
4. `ufw default deny incoming`
5. `ufw default allow outgoing`
6. Allow user-selected ports
7. `ufw --force enable`

**Verification:**

- [ ] SSH allowed before any reset
- [ ] SSH re-allowed immediately after reset
- [ ] Default deny incoming set
- [ ] Default allow outgoing set
- [ ] All selected ports allowed
- [ ] UFW enabled
- [ ] Idempotent: same selection = same result

---

### F9: IPv4/IPv6 Support

| Attribute      | Value                    |
| -------------- | ------------------------ |
| Source         | 02-FEATURES.md §F9       |
| Components     | server-firewall.sh       |
| New Files      | None                     |
| Modified Files | None                     |

**Implementation Notes:**

UFW handles IPv4/IPv6 automatically when rules are added without IP specification. No explicit verification of /etc/default/ufw needed (per design decision).

**Verification:**

- [ ] Rules apply to IPv4 (verified via `ufw status`)
- [ ] Rules apply to IPv6 (verified via `ufw status`)

---

### F10: CLI Option

| Attribute      | Value                    |
| -------------- | ------------------------ |
| Source         | 02-FEATURES.md §F10      |
| Components     | ServerFirewallCommand    |
| New Files      | None                     |
| Modified Files | None                     |

**Interface Contract:**

| Method/Function           | Input                          | Output       | Errors |
| ------------------------- | ------------------------------ | ------------ | ------ |
| parseAndFilterAllowOption | string, array\<int,string\>    | array\<int\> | None   |

**Data Structures:**

| Name         | Type                | Fields | Purpose                |
| ------------ | ------------------- | ------ | ---------------------- |
| --allow      | InputOption::VALUE_REQUIRED | Comma-separated ports | Non-interactive mode |
| --force      | InputOption::VALUE_NONE     | Boolean | Skip confirmation     |
| --server     | InputOption::VALUE_REQUIRED | Server name | Server selection      |

**Verification:**

- [ ] Accepts `--allow=80,443,3306`
- [ ] Skips multi-select when --allow provided
- [ ] Works with --server for non-interactive flow
- [ ] Shows confirmation unless --force used

---

### F11: Filter Invalid Ports

| Attribute      | Value                    |
| -------------- | ------------------------ |
| Source         | 02-FEATURES.md §F11      |
| Components     | ServerFirewallCommand    |
| New Files      | None                     |
| Modified Files | ServerFirewallCommand    |

**Interface Contract:**

| Method/Function           | Input                          | Output       | Errors |
| ------------------------- | ------------------------------ | ------------ | ------ |
| parseAndFilterAllowOption | string, array\<int,string\>    | array\<int\> | None   |

**Error Taxonomy:**

| Condition                | Message                                                  | Behavior    |
| ------------------------ | -------------------------------------------------------- | ----------- |
| Ports filtered out       | "Ports X, Y are not listening services and will be ignored" | Info note |
| All ports filtered       | "No valid listening ports specified. Only SSH port will be allowed." | Warning |

**Verification:**

- [ ] Filters --allow list to detected ports only
- [ ] Displays single summary message for filtered ports
- [ ] Final list contains only valid listening ports + SSH
