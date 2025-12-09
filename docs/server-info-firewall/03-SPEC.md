# Technical Specification - Server Info Firewall Display

## Context

### From PRD

Extend `server:info` to display firewall status and open ports (matching `server:firewall` output format). Consolidate UFW detection into `server-info` playbook for single SSH call and data reuse. Refactor `server:firewall` to reuse shared detection from `serverInfo()` method.

**Key constraints:**

- No additional SSH calls (integrate into existing playbook)
- Output format must match `server:firewall` exactly
- Existing `server:info` sections unchanged (firewall is additive)

### From Features

- **F1:** Add UFW detection to `server-info` playbook
- **F2:** `serverInfo()` returns firewall data automatically (passthrough)
- **F3:** Display "Firewall" section in `server:info` with identical format to `server:firewall`
- **F4:** Refactor `server:firewall` to use `serverInfo()` for detection
- **F5:** Remove detect mode from `server-firewall` playbook (apply-only)

### Specification Summary

- **Playbook:** Add 3 UFW detection functions to `server-info.sh`, output `ufw_installed`, `ufw_active`, `ufw_rules` YAML keys
- **Display:** Extract `displayFirewallStatus()` method for reuse between commands
- **Data Flow:** Firewall data passes through `serverInfo()` automatically via playbook YAML output
- **Refactor:** `ServerFirewallCommand` replaces detection playbook call with `serverInfo()`, maps keys to existing variables
- **Safety:** No changes to apply mode logic; SSH port protection unchanged

---

## Overview

Consolidate UFW firewall detection into the `server-info` playbook, enabling both `server:info` and `server:firewall` commands to share detection logic. The `server:firewall` command retains its apply-mode playbook for firewall configuration.

**Components:**

| Component | Type | Purpose |
| --- | --- | --- |
| `server-info.sh` | Playbook | Detect UFW status, rules, and ports |
| `ServerInfoCommand` | Command | Display firewall section |
| `ServerFirewallCommand` | Command | Use shared detection, apply firewall rules |
| `server-firewall.sh` | Playbook | Apply-only firewall configuration |

**Architecture:**

```
server:info                          server:firewall
    │                                     │
    ▼                                     ▼
selectServer()                       selectServer()
    │                                     │
    ▼                                     ▼
serverInfo()◄────────────────────────serverInfo()
    │                                     │
    ▼                                     ▼
server-info.sh ──────────────────► {ufw_installed, ufw_active, ufw_rules, ports}
    │                                     │
    ▼                                     ▼
displayServerInfo()                  displayFirewallStatus()
    │                                     │
    ├─► displayFirewallStatus()           ▼
    │                                promptPortSelection()
    ▼                                     │
[end]                                     ▼
                                     server-firewall.sh (apply mode only)
                                          │
                                          ▼
                                     [end]
```

**Design Decisions:**

- **Single playbook detection:** UFW detection in `server-info.sh` eliminates duplicate code and ensures consistent results
- **Passthrough data flow:** `serverInfo()` returns raw playbook YAML; no transformation needed
- **Shared display method:** `displayFirewallStatus()` extracted for reuse, not a trait (only 2 commands need it)

---

## Feature Specifications

### F1: Playbook UFW Detection

| Attribute | Value |
| --- | --- |
| Source | 02-FEATURES.md §F1 |
| Components | `server-info.sh` |
| New Files | None |
| Modified Files | `playbooks/server-info.sh` |

**Interface Contract:**

| Function | Input | Output | Errors |
| --- | --- | --- | --- |
| `check_ufw_installed()` | None | `"true"` or `"false"` | None |
| `check_ufw_active()` | None | `"true"` or `"false"` | None |
| `get_ufw_rules()` | None | Lines of `port/proto` | None |

**Playbook Contract:**

| Variable | Type | Required | Description |
| --- | --- | --- | --- |
| DEPLOYER_OUTPUT_FILE | string | Yes | YAML output path |

Output (added keys):

```yaml
ufw_installed: true|false
ufw_active: true|false
ufw_rules:
  - "22/tcp"
  - "80/tcp"
  - "443/tcp"
```

**Integration Points:**

- `get_listening_services()`: Existing helper, already used for `ports` output
- `run_cmd`: Execute `ufw status` with appropriate permissions

**Error Taxonomy:**

| Condition | Message | Behavior |
| --- | --- | --- |
| UFW not installed | N/A | Set `ufw_installed: false`, skip rule detection |
| UFW command fails | N/A | Set `ufw_active: false`, empty rules array |

**Edge Cases:**

| Scenario | Behavior |
| --- | --- |
| UFW installed but inactive | `ufw_installed: true`, `ufw_active: false`, `ufw_rules: []` |
| UFW active with no rules | `ufw_installed: true`, `ufw_active: true`, `ufw_rules: []` |
| IPv6 duplicate rules | Filter out `(v6)` suffix rules |

**Verification:**

- Running `server:info` on server with UFW shows firewall status
- YAML output contains `ufw_installed`, `ufw_active`, `ufw_rules` keys

---

### F2: ServersTrait Integration

| Attribute | Value |
| --- | --- |
| Source | 02-FEATURES.md §F2 |
| Components | `ServersTrait` |
| New Files | None |
| Modified Files | None (passthrough) |

**Interface Contract:**

| Method | Input | Output | Errors |
| --- | --- | --- | --- |
| `serverInfo()` | `ServerDTO` | `ServerDTO` with `info` containing firewall keys | `Command::FAILURE` |

**Data Structures:**

| Name | Type | Fields | Purpose |
| --- | --- | --- | --- |
| Server info array | `array<string, mixed>` | `ufw_installed`, `ufw_active`, `ufw_rules`, `ports` (existing) | Firewall state |

**Integration Points:**

- `executePlaybookSilently()`: Returns parsed YAML including new firewall keys
- `ServerDTO::withInfo()`: Stores info array unchanged

**Verification:**

- `$server->info['ufw_installed']` accessible after `serverInfo()` call
- Existing info keys (`distro`, `permissions`, `ports`, etc.) unchanged

---

### F3: Firewall Display

| Attribute | Value |
| --- | --- |
| Source | 02-FEATURES.md §F3 |
| Components | `ServerInfoCommand`, `ServerFirewallCommand` |
| New Files | None |
| Modified Files | `app/Console/Server/ServerInfoCommand.php`, `app/Console/Server/ServerFirewallCommand.php` |

**Interface Contract:**

| Method | Input | Output | Errors |
| --- | --- | --- | --- |
| `displayFirewallStatus()` | `array $info` | void (console output) | None |

**Data Structures:**

| Name | Type | Fields | Purpose |
| --- | --- | --- | --- |
| Firewall info | `array` | `ufw_installed: bool`, `ufw_active: bool`, `ufw_rules: array<string>`, `ports: array<int, string>` | Display input |

**Integration Points:**

- `displayDeets()`: BaseCommand method for formatted output
- `extractPortsFromRules()`: Existing method in `ServerFirewallCommand`, extract to shared location

**Display Format:**

```
Firewall: Not installed
```

OR

```
Firewall: Inactive
```

OR

```
Firewall: Active

Open Ports
  Port 22: sshd
  Port 80: caddy
  Port 443: caddy
```

**Edge Cases:**

| Scenario | Behavior |
| --- | --- |
| UFW not installed | Display "Firewall: Not installed" |
| UFW inactive | Display "Firewall: Inactive" |
| UFW active, no rules | Display "Firewall: Active", "Open Ports: None" |
| Port has no process match | Display "Port X: unknown" |

**Verification:**

- `server:info` output includes "Firewall" section
- Format matches `server:firewall` detection display exactly
- "Services" section (listening ports) remains unchanged

---

### F4: Firewall Command Refactor

| Attribute | Value |
| --- | --- |
| Source | 02-FEATURES.md §F4 |
| Components | `ServerFirewallCommand` |
| New Files | None |
| Modified Files | `app/Console/Server/ServerFirewallCommand.php` |

**Interface Contract:**

| Method | Input | Output | Errors |
| --- | --- | --- | --- |
| `execute()` | `InputInterface`, `OutputInterface` | `Command::SUCCESS` or `Command::FAILURE` | Exception propagation |

**Data Structures:**

| Name | Type | Fields | Purpose |
| --- | --- | --- | --- |
| Detection result | Removed | N/A | Replaced by `$server->info` |

**Integration Points:**

- `selectServer()`: Now returns `ServerDTO` with firewall info populated
- `$server->info`: Access `ufw_installed`, `ufw_active`, `ufw_rules`, `ports`

**Code Changes:**

Before:
```php
$detection = $this->executePlaybookSilently(
    $server,
    'server-firewall',
    'Detecting firewall status...',
    ['DEPLOYER_MODE' => 'detect', 'DEPLOYER_PERMS' => $permissions],
);
$ufwInstalled = $detection['ufw_installed'] ?? false;
```

After:
```php
// Detection already done in selectServer() -> serverInfo()
$info = $server->info;
$ufwInstalled = $info['ufw_installed'] ?? false;
$ufwActive = $info['ufw_active'] ?? false;
$ufwRules = $info['ufw_rules'] ?? [];
$ports = $info['ports'] ?? [];
```

**Error Taxonomy:**

| Condition | Message | Behavior |
| --- | --- | --- |
| Server selection fails | (existing) | Return `Command::FAILURE` |
| No `info` on server | (existing) | Return `Command::FAILURE` |

**Verification:**

- `server:firewall` shows same detection output as before
- No `server-firewall` playbook called in detect mode
- Port selection and apply flow unchanged

---

### F5: Apply-Only Playbook

| Attribute | Value |
| --- | --- |
| Source | 02-FEATURES.md §F5 |
| Components | `server-firewall.sh` |
| New Files | None |
| Modified Files | `playbooks/server-firewall.sh` |

**Playbook Contract:**

| Variable | Type | Required | Description |
| --- | --- | --- | --- |
| DEPLOYER_OUTPUT_FILE | string | Yes | YAML output path |
| DEPLOYER_PERMS | string | Yes | `root` or `sudo` |
| DEPLOYER_SSH_PORT | string | Yes | SSH port to always allow |
| DEPLOYER_ALLOWED_PORTS | string | Yes | Comma-separated ports |

Output:

```yaml
status: success
ufw_installed: true
ufw_enabled: true
rules_applied: 3
ports_allowed:
  - 22
  - 80
  - 443
```

**Code Removal:**

Remove from `server-firewall.sh`:
- `DEPLOYER_MODE` variable validation
- `detect_mode()` function
- `check_ufw_installed()` function
- `check_ufw_active()` function
- `get_ufw_rules()` function
- Mode switch in `main()`

Keep:
- All apply functions (`validate_ssh_port`, `install_ufw_if_missing`, `allow_ssh_port`, `reset_ufw`, `set_default_policies`, `allow_selected_ports`, `enable_ufw`)
- `apply_mode()` function (renamed to `main()` content)
- SSH safety sequence

**Error Taxonomy:**

| Condition | Message | Behavior |
| --- | --- | --- |
| SSH port not in allowed list | "SSH port X must always be allowed" | Exit 1 via `fail()` |
| UFW install fails | "Failed to install UFW" | Exit 1 via `fail()` |
| UFW enable fails | "Failed to enable UFW" | Exit 1 via `fail()` |

**Verification:**

- `server:firewall` apply mode works unchanged
- No detect mode code remains in playbook
- SSH safety sequence preserved

---

## Implementation Notes

### Shared Display Logic

Extract `displayFirewallStatus()` as a private method in `ServerInfoCommand`, then either:
1. Duplicate in `ServerFirewallCommand` (2 copies, simple)
2. Create `FirewallDisplayTrait` (if more commands need it later)

Recommendation: Start with duplication (option 1), extract to trait only if a third command needs it.

### Port Extraction

The `extractPortsFromRules()` method in `ServerFirewallCommand` parses UFW rules:
```php
// "22/tcp" -> 22
preg_match('/^(\d+)/', $rule, $matches);
```

This method stays in `ServerFirewallCommand` as it's only needed for port selection logic.

### YAML Key Naming

Use consistent naming between playbooks:
- `ufw_installed` (bool)
- `ufw_active` (bool)
- `ufw_rules` (array of strings like "22/tcp")
- `ports` (map of port number to process name) - already exists
