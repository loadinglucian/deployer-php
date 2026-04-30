---
name: deployerphp-playbook-authoring
description: Author and update DeployerPHP playbooks with idempotent bash, DEPLOYER_* environment validation, helper usage, YAML output contracts, credential patterns, and logging/logrotate conventions. Use when editing playbooks/* or playbook-adjacent command behavior.
---

# Playbook Authoring

Use this skill when tasks modify provisioning/install/deploy shell playbooks.

## Protocol

1. Read `references/playbook-rules.md` before making playbook edits.
2. Keep scripts idempotent, non-interactive, and aligned with required
   environment-variable validation and output contracts.
3. Follow helper usage and bash-style constraints from the reference.
4. Preserve logging and logrotate lifecycle behavior when resources are created
   or removed.

## References

- `references/playbook-rules.md` - full playbook rules and examples migrated
  from `AGENTS.md`.
