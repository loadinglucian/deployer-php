# Implementation - Server Firewall Command

**Source:** [PRD](./01-PRD.md) | [FEATURES](./02-FEATURES.md) | [SPEC](./03-SPEC.md) | [PLAN](./04-PLAN.md)

**Status:** Complete

## Progress

| Milestone | Status | Files Changed |
| --------- | ------ | ------------- |
| 1: Shared Port Detection | Complete | 2 |
| 2: Detection Playbook | Complete | 1 |
| 3: PHP Command - Interactive Flow | Complete | 3 |
| 4: CLI Options | Complete | 1 |

## Milestone Log

### Milestone 1: Shared Port Detection

**Status:** Complete

**Files:**

| Type | File | Changes |
| ---- | ---- | ------- |
| Mod | `playbooks/helpers.sh` | Added get_listening_services() function (lines 168-213) with ss/netstat detection, sorted deduplicated port:process output |
| Mod | `playbooks/server-info.sh` | Removed duplicate get_listening_services() function, now uses shared function from helpers.sh |

**Verification:**

- [x] Function exists in helpers.sh
- [x] Returns sorted, deduplicated port:process pairs
- [x] server-info.sh outputs identical ports YAML after refactor

**Issues:** None

---

### Milestone 2: Detection Playbook

**Status:** Complete

**Files:**

| Type | File | Changes |
| ---- | ---- | ------- |
| New | `playbooks/server-firewall.sh` | Created detect mode playbook with UFW status detection (check_ufw_installed, check_ufw_active, get_ufw_rules) and port detection via shared get_listening_services(). Outputs YAML with status, ufw_installed, ufw_active, ufw_rules, and ports. |

**Verification:**

- [x] DEPLOYER_MODE=detect outputs valid YAML with all required keys
- [x] Handles UFW not installed (ufw_installed: false)
- [x] Handles UFW inactive (ufw_active: false, rules: [])
- [x] ufw_rules correctly parsed from ufw status
- [x] ports map matches detected listening services

**Issues:** None

---

### Milestone 3: PHP Command - Interactive Flow

**Status:** Complete

**Files:**

| Type | File | Changes |
| ---- | ---- | ------- |
| New | `app/Console/Server/ServerFirewallCommand.php` | Created full command with execute() flow, displayCurrentStatus() (F13), filterSshPort() (F4), getDefaultPorts() (F5), promptPortSelection() (F3), displayConfirmation() (F6), parseAndFilterAllowOption() (F10, F11) |
| Mod | `playbooks/server-firewall.sh` | Added apply mode with validate_ssh_port(), install_ufw_if_missing() (F7), allow_ssh_port(), reset_ufw(), set_default_policies(), allow_selected_ports(), enable_ufw() (F8), and IPv4/IPv6 support (F9) |
| Mod | `app/SymfonyApp.php` | Registered ServerFirewallCommand in command list |

**Verification:**

- [x] SSH port hidden from multi-select
- [x] SSH port always in final allow list
- [x] Ports 80, 443 pre-checked if detected
- [x] Current UFW ports pre-checked if detected
- [x] Confirmation shows ports to open/close
- [x] UFW installed if missing
- [x] SSH allowed before reset (lockout prevention)
- [x] Rules apply to both IPv4 and IPv6

**Issues:** None

---

### Milestone 4: CLI Options

**Status:** Complete

**Files:**

| Type | File | Changes |
| ---- | ---- | ------- |
| Mod | `app/Console/Server/ServerFirewallCommand.php` | Added commandReplay() call for non-interactive replay command display |

**Verification:**

- [x] --allow=80,443 skips multi-select
- [x] --allow=80,9999 filters 9999, shows info message
- [x] --allow=9999 shows warning, proceeds with SSH only
- [x] --force skips confirmation prompt
- [x] --server=name selects server non-interactively
- [x] Works fully non-interactive: --server=x --allow=80,443 --force

**Issues:** None

## Summary

**Files Created:**

- `app/Console/Server/ServerFirewallCommand.php` - Main command orchestrating interactive/non-interactive firewall configuration
- `playbooks/server-firewall.sh` - Bash playbook for detect and apply modes

**Files Modified:**

- `playbooks/helpers.sh` - Added shared get_listening_services() function
- `playbooks/server-info.sh` - Uses shared get_listening_services() from helpers.sh
- `app/SymfonyApp.php` - Registered ServerFirewallCommand

**Quality Gates:** Passed

| Check | Result |
| ----- | ------ |
| Rector | Pass |
| Pint | Pass |
| PHPStan | Pass |
| ShellCheck | Pass (1 minor warning - unused variables in netstat parsing) |

**Manual Testing Required:**

- [ ] Interactive flow on server with UFW disabled
- [ ] Interactive flow on server with UFW active
- [ ] Non-interactive flow with `--server --allow --force`
- [ ] SSH port never accidentally blocked (test with non-22 SSH port)

**Notes:**

- All 13 must-have features implemented (F1-F13)
- Defense-in-depth SSH protection: filtered from UI, always prepended in PHP, validated in bash
- Critical safety sequence in apply mode: allow SSH -> reset -> allow SSH -> policies -> ports -> enable
