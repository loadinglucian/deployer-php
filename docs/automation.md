# Automation & AI Guide

<!-- toc -->

- [Command Replays](#command-replays)
- [Quiet Mode](#quiet-mode)
- [AI Automation](#ai-automation)
    - [Permission Tiers](#permission-tiers)

<!-- /toc -->

While managing servers and deployments manually works well during development, you'll often want to automate these tasks for CI/CD pipelines, scheduled jobs, or AI-assisted workflows. This guide explains the automation model and how to use AI safely with DeployerPHP.

<a name="command-replays"></a>

## Command Replays

Every DeployerPHP command displays a non-interactive command replay at the end of execution. The replay reflects your interactive choices and can be reused as the starting point for scripts, CI jobs, and repeatable runbooks.

The intended workflow is simple:

1. Run a command interactively once.
2. Validate the behavior and outcome.
3. Reuse the generated replay in automation contexts.

<a name="quiet-mode"></a>

## Quiet Mode

Quiet mode is intended for non-interactive automation runs where human-readable terminal output is unnecessary. In this mode, command output is minimized and errors still surface.

When using quiet, non-interactive execution:

- Provide all required command inputs up front.
- Expect prompt-driven flows to be bypassed.
- Treat replay output as the canonical source for repeatable command construction.

<a name="ai-automation"></a>

## AI Automation

If you use AI tools like Claude, Codex, Cursor, or OpenCode, you can create a skills file that guides agents on safely interacting with your DeployerPHP-managed servers. This is useful when debugging issues with your application in production. Agents can read logs or execute remote, non-destructive commands on your server to investigate and resolve problems.

> [!IMPORTANT]
> **Use at your own risk!** Granting AI agents access to production servers can be risky. Always review generated skills and monitor AI-initiated actions. You are solely responsible for any changes, data loss, or issues arising from AI-assisted debugging.

Run `scaffold:ai` from your project directory to generate agent skills.

DeployerPHP will select the AI agent directory using this flow:

1. If exactly one supported directory exists (`.agents` or `.claude`), it is selected automatically.
2. If multiple agent directories exist, you'll be prompted to choose which one to use.
3. If no agent directories exist, you'll be prompted to choose which one to create.

The supported directories are:

- **`.agents`**: Shared skills directory for Codex, Cursor, and OpenCode (`.agents/skills/`)
- **`.claude`**: Claude skills directory (`.claude/skills/`)

> [!INFO]
> The selection flow above is based on whether agent directories already exist in your project.

<a name="permission-tiers"></a>

### Permission Tiers

When scaffolding AI skills, you'll select a permission tier that determines what your AI assistant can do on your servers. Each tier builds on the previous one, adding more capabilities:

| Tier     | Access Level                  | Best For                              |
| -------- | ----------------------------- | ------------------------------------- |
| Observer | Read-only                     | Viewing logs and server information   |
| Debugger | Inspect + safe shell commands | Investigating issues with more agency |
| Admin    | Full infrastructure access    | Agents can manage everything          |

**Observer** is the most restrictive tier. Your AI assistant can run read-only DeployerPHP commands like `server:info` and `server:logs` to view server state and logs, but it cannot run shell commands via `server:run` or make any changes. This is ideal for getting help understanding what's happening on your server without any risk of accidental modifications.

**Debugger** is the default tier and strikes a balance between utility and safety. In addition to observer capabilities, your assistant can run safe, non-destructive shell commands like `ls`, `cat`, `grep`, and `df`. This lets it actively investigate issues by exploring the filesystem and checking resource usage, but it cannot restart services, modify files, or run deployments.

**Admin** grants full access to DeployerPHP commands. Your assistant can deploy code, manage services, configure sites, and perform any operation you could do manually. Only use this tier with AI agents you fully trust, and always review the generated skills before enabling them.

> [!INFO]
> Start with the Debugger tier. It provides enough access for most troubleshooting scenarios while keeping guardrails in place. You can always scaffold a higher tier later if needed.

The generated skills file provides your AI assistant with:

- **Inventory context**: Understanding of your `.deployer/inventory.yml` structure
- **Deployment layout**: Knowledge of the release directory structure
- **Safe debugging commands**: Commands for viewing logs, checking status, and reading files
- **Guardrails**: Explicit restrictions preventing destructive operations like deployments, service restarts, or configuration changes

This ensures your AI assistant can help troubleshoot issues on your servers without accidentally running commands that could affect production stability.

For command-level behavior details, see [Scaffold Reference](reference-scaffold.md) and [Server Reference](reference-server.md).
