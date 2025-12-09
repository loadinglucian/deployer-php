# Server Info Firewall Display

## Context

## Product Summary

- **Problem:** Firewall status requires running a separate `server:firewall` command; users want unified server visibility in `server:info`
- **Target Users:** Server administrators using the deployer CLI
- **Key Decision:** Consolidate firewall detection into `server-info` playbook for single SSH call and data reuse
- **Refactoring:** `server:firewall` command will reuse shared detection from `serverInfo()` method
- **Journey:** View complete server status including firewall in one command

---

## Overview

Extend the `server:info` command to display firewall status (active/inactive/not installed) and open ports, matching the output format of `server:firewall`. This consolidates server visibility into a single command while enabling firewall data reuse across the codebase.

## Goals and Objectives

- Display firewall status in `server:info` identical to `server:firewall` output
- Single SSH call for all server information (no additional network round-trip)
- Enable firewall data access via `serverInfo()` for any command needing it
- Reduce code duplication by sharing firewall detection logic

## Scope

**Included:**

- Add UFW detection to `server-info` playbook
- Display "Firewall" section in `server:info` output
- Return firewall data from `serverInfo()` method in `ServersTrait`
- Refactor `server:firewall` to reuse shared detection

**Excluded:**

- Firewall management/modification (remains in `server:firewall`)
- Support for firewalls other than UFW
- Displaying firewall status during interactive server selection prompts

## Target Audience

Server administrators who want complete server status visibility without running multiple commands.

## Functional Requirements

### Priority 1 (Must have)

- `server:info` displays a "Firewall" section with status (Active/Inactive/Not installed)
- When active, display "Open Ports" with port numbers and process names
- Output format identical to `server:firewall` detection display
- `server-info` playbook detects UFW status and rules in single SSH call
- `serverInfo()` method returns firewall data in its result array
- `server:firewall` command reuses `serverInfo()` for detection phase
- Remove duplicate detection logic from `server-firewall` playbook (keep apply logic only)
- Consistent port display format between "Services/Ports" and "Firewall/Open Ports" sections

## Non-Functional Requirements

- **Performance:** No additional SSH calls; firewall detection integrated into existing `server-info` playbook
- **Consistency:** Firewall display format must match `server:firewall` exactly
- **Backwards Compatibility:** Existing `server:info` output sections unchanged; firewall is additive

## User Journeys

1. **View Complete Server Status:** User runs `server:info`, sees all system information including firewall status and open ports in a single output, without needing to run `server:firewall` separately.

2. **Configure Firewall:** User runs `server:firewall`, which now uses the same detection mechanism as `server:info`, ensuring consistent firewall status reporting before showing configuration options.

## Success Metrics

| Metric                    | Target                             |
| ------------------------- | ---------------------------------- |
| SSH calls for server:info | 1 (unchanged)                      |
| Firewall display parity   | 100% match with server:firewall    |
| Code duplication          | Detection logic in single location |

## Implementation Phases

### Phase 1: Playbook Integration

- Add UFW detection to `server-info` playbook (status, rules, ports mapping)
- Update `ServerInfoCommand` to display firewall section
- Update `serverInfo()` to include firewall data in return value

### Phase 2: Command Refactoring

- Refactor `server:firewall` to use `serverInfo()` for detection
- Remove detection logic from `server-firewall` playbook (keep apply mode only)
- Ensure consistent behavior between commands

## Open Questions

- None

## Assumptions

- All target servers use UFW for firewall management (Ubuntu/Debian standard)
- The `get_listening_services()` helper already exists in playbook helpers
- UFW detection commands (`ufw status`) work consistently across supported distros
