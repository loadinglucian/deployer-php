# Implementation Plan - Server Info Firewall Display

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

**Critical Path:** `F1 -> F2 -> F3` and `F1 -> F2 -> F4 -> F5`

### From Spec

- **Playbook:** Add 3 UFW detection functions to `server-info.sh`, output `ufw_installed`, `ufw_active`, `ufw_rules` YAML keys
- **Display:** Extract `displayFirewallStatus()` method for reuse between commands
- **Data Flow:** Firewall data passes through `serverInfo()` automatically via playbook YAML output
- **Refactor:** `ServerFirewallCommand` replaces detection playbook call with `serverInfo()`, maps keys to existing variables
- **Safety:** No changes to apply mode logic; SSH port protection unchanged

### Plan Summary

- **4 milestones:** Playbook detection → parallel command updates → playbook cleanup
- **File changes:** 2 playbooks modified, 2 PHP commands modified
- **Parallel opportunity:** Milestones 2a/2b can run concurrently (different files)
- **Key verification:** Each milestone independently testable via CLI commands
- **Risk mitigation:** Apply-only playbook cleanup is last milestone, after command refactor verified

---

## Overview

Implement UFW detection in `server-info.sh`, add firewall display to `ServerInfoCommand`, refactor `ServerFirewallCommand` to use shared detection, then clean up `server-firewall.sh` to apply-only mode.

**Source:** [PRD](./01-PRD.md) | [FEATURES](./02-FEATURES.md) | [SPEC](./03-SPEC.md)

## File Changes

| Type | File | Purpose |
| --- | --- | --- |
| Mod | `playbooks/server-info.sh` | Add UFW detection functions and YAML output |
| Mod | `app/Console/Server/ServerInfoCommand.php` | Add firewall display section |
| Mod | `app/Console/Server/ServerFirewallCommand.php` | Use `$server->info` instead of detection playbook |
| Mod | `playbooks/server-firewall.sh` | Remove detect mode, keep apply-only |

## Prerequisites

**Reference Patterns:**

- `playbooks/server-firewall.sh` lines 56-110 - UFW detection functions to move
- `app/Console/Server/ServerFirewallCommand.php` lines 216-248 - `displayCurrentStatus()` method to replicate
- `app/Console/Server/ServerInfoCommand.php` lines 130-146 - Existing "Services" display pattern

---

## Milestones

### Milestone 1: Playbook UFW Detection

| Features | F1, F2 |
| --- | --- |

**Deliverables:**

- `playbooks/server-info.sh` with UFW detection functions and YAML output

**Steps:**

1. Add "UFW Detection Functions" section after "Helper Functions" in `server-info.sh`
2. Create `check_ufw_installed()` - return `"true"` if `command -v ufw` succeeds
3. Create `check_ufw_active()` - parse `ufw status` output for "Status: active"
4. Create `get_ufw_rules()` - extract port/proto from `ufw status`, filter IPv6 duplicates
5. Add detection calls in `main()` after "Detecting sites configuration..."
6. Add YAML output for `ufw_installed`, `ufw_active`, `ufw_rules` keys after `sites_config`

**Integration:** Functions reuse `run_cmd` helper for permission handling; YAML appended to existing output structure

**Verification:**

- [ ] Run playbook directly: `DEPLOYER_OUTPUT_FILE=/tmp/test.yaml bash playbooks/server-info.sh` (with helpers inlined)
- [ ] Output YAML contains `ufw_installed`, `ufw_active`, `ufw_rules` keys
- [ ] Works on server with UFW active, inactive, and uninstalled

**Enables:** Milestones 2a, 2b

---

### Milestone 2a: ServerInfoCommand Display (parallel with 2b)

| Features | F3 |
| --- | --- |
| Parallel | 2b |

**Deliverables:**

- `displayFirewallStatus()` method in `ServerInfoCommand`
- Firewall section in `displayServerInfo()` output

**Steps:**

1. Add `displayFirewallStatus(array $info)` private method after `displayServerInfo()`
2. Implement status display: "Not installed" / "Inactive" / "Active" per 03-SPEC.md §F3
3. Implement "Open Ports" display when active, using `extractPortsFromRules()` logic
4. Call `$this->displayFirewallStatus($info)` in `displayServerInfo()` after "Services" section

**Integration:** Uses `displayDeets()` from BaseCommand; follows existing display pattern

**Verification:**

- [ ] `bin/deployer server:info` shows "Firewall" section
- [ ] Format matches `server:firewall` detection output exactly
- [ ] "Services" section unchanged

**Enables:** Milestone 3

---

### Milestone 2b: ServerFirewallCommand Refactor (parallel with 2a)

| Features | F4 |
| --- | --- |
| Parallel | 2a |

**Deliverables:**

- `ServerFirewallCommand::execute()` using `$server->info` for detection

**Steps:**

1. Remove `executePlaybookSilently()` detection call (lines 72-80)
2. Extract firewall data from `$server->info` after `selectServer()`:

   ```php
   $info = $server->info;
   $ufwInstalled = $info['ufw_installed'] ?? false;
   $ufwActive = $info['ufw_active'] ?? false;
   $ufwRules = $info['ufw_rules'] ?? [];
   $ports = $info['ports'] ?? [];
   ```

3. Update variable types: `$ufwInstalled` and `$ufwActive` may be strings from YAML, cast to bool
4. Verify `displayCurrentStatus()`, `extractPortsFromRules()`, and port selection logic unchanged

**Integration:** `selectServer()` already calls `serverInfo()` which executes `server-info` playbook

**Verification:**

- [ ] `bin/deployer server:firewall` shows same detection output as before
- [ ] Port selection prompt works correctly
- [ ] Apply flow unchanged (tested in Milestone 3)

**Enables:** Milestone 3

---

### Milestone 3: Apply-Only Playbook

| Features | F5 |
| --- | --- |

**Deliverables:**

- `playbooks/server-firewall.sh` with detect mode removed

**Steps:**

1. Remove `DEPLOYER_MODE` validation (line 41)
2. Remove "UFW Detection Functions" section (lines 48-110): `check_ufw_installed()`, `check_ufw_active()`, `get_ufw_rules()`
3. Remove `detect_mode()` function (lines 210-284)
4. Remove mode switch in `main()` (lines 348-361)
5. Inline `apply_mode()` content directly in `main()`
6. Update header comments to remove detect mode documentation

**Integration:** `ServerFirewallCommand` now only calls this playbook with apply variables

**Verification:**

- [ ] `bin/deployer server:firewall` full flow works (detection via server-info, apply via server-firewall)
- [ ] SSH port always allowed (safety sequence preserved)
- [ ] No detect mode code remains in playbook

**Enables:** None (final milestone)

---

## Implementation Notes

### YAML Boolean Handling

The playbook outputs `ufw_installed: true` as a string. In PHP, cast explicitly:

```php
$ufwInstalled = filter_var($info['ufw_installed'] ?? false, FILTER_VALIDATE_BOOLEAN);
```

### Port Extraction Logic

Copy the regex pattern from `ServerFirewallCommand::extractPortsFromRules()` for use in `ServerInfoCommand::displayFirewallStatus()`:

```php
// Extract port number from "22/tcp" format
preg_match('/^(\d+)/', $rule, $matches);
$port = (int) $matches[1];
```

### Display Consistency

Both commands must display firewall status identically:

```
Firewall: Active

Open Ports
  Port 22: sshd
  Port 80: caddy
```

Use `displayDeets(['Firewall' => 'Active'])` followed by `displayDeets(['Open Ports' => $portsList])`.

---

## Completion Criteria

- [ ] All milestones verified
- [ ] Quality gates pass (Rector, Pint, PHPStan)
- [ ] `server:info` shows firewall status matching `server:firewall` format
- [ ] `server:firewall` detection and apply flow work correctly
- [ ] No duplicate detection code between playbooks
