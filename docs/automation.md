# Automation

<!-- toc -->

- [Command Replays](#command-replays)
- [Quiet Mode](#quiet-mode)
- [AI Automation](#ai-automation)
    - [Permission Tiers](#permission-tiers)

<!-- /toc -->

While managing servers and deployments manually works well during development, you'll often want to automate these tasks for CI/CD pipelines, scheduled jobs, or AI-assisted workflows. DeployerPHP provides several features to make automation seamless.

<a name="command-replays"></a>

## Command Replays

Every DeployerPHP command displays a **non-interactive command replay** at the end of execution. This replay shows the exact command with all options you selected during the interactive session, making it easy to copy and use in scripts or CI pipelines.

For example, when you run `deployer server:add` interactively and fill in the prompts, you'll see output like:

```shell
# Non-interactive command replay
deployer server:add \
    --name=production \
    --host=192.168.1.100 \
    --port=22 \
    --username=root \
    --private-key-path=~/.ssh/id_rsa
```

You can copy this command directly into your automation scripts. The replay teaches you the CLI syntax as you use the tool: run interactively once, then automate with the generated command.

<a name="quiet-mode"></a>

## Quiet Mode

If you want minimal output, use the `--quiet` (or `-q`) global option. This option is available on all commands and suppresses all output except errors.

```shell
deployer site:deploy \
    --domain=example.com \
    --repo=git@github.com:user/app.git \
    --branch=main \
    --yes \
    --quiet
```

When using quiet mode, you must provide **all required options via CLI**. DeployerPHP can't prompt for missing values when output is suppressed. If a required option is missing, you'll receive a clear error:

```
Option --domain is required when using --quiet mode
```

> [!NOTE]
> The `--yes` flag skips confirmation prompts. In automation, you'll typically combine `--quiet` with `--yes` to run completely non-interactively.

<a name="ai-automation"></a>

## AI Automation

If you use AI tools like Claude, Codex, Cursor, or OpenCode, you can create a skills file that guides agents on safely interacting with your DeployerPHP-managed servers. This is useful when debugging issues with your application in production. Agents can read logs or execute remote, non-destructive commands on your server to investigate and resolve problems.

> [!WARNING]
> **Use at your own risk!** Granting AI agents access to production servers can be risky. Always review generated skills and monitor AI-initiated actions. You are solely responsible for any changes, data loss, or issues arising from AI-assisted debugging.

Run the `scaffold:ai` command from your project directory:

```shell
deployer scaffold:ai
```

DeployerPHP will select the AI agent using this flow:

1. If exactly one agent directory exists (e.g., `.claude`, `.codex`, `.cursor`, `.opencode`), it is selected automatically.
2. If multiple agent directories exist, you'll be prompted to choose which one to use.
3. If no agent directories exist, you'll be prompted to choose which one to create.

The supported agents are:

- **Claude**: Creates skills in `.claude/skills/`
- **Codex**: Creates skills in `.codex/skills/`
- **Cursor**: Creates skills in `.cursor/skills/`
- **OpenCode**: Creates skills in `.opencode/skill/` (also discovers `.claude/skills/`)

> [!NOTE]
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

> [!TIP]
> Start with the Debugger tier. It provides enough access for most troubleshooting scenarios while keeping guardrails in place. You can always scaffold a higher tier later if needed.

The generated skills file provides your AI assistant with:

- **Inventory context**: Understanding of your `.deployer/inventory.yml` structure
- **Deployment layout**: Knowledge of the release directory structure
- **Safe debugging commands**: Commands for viewing logs, checking status, and reading files
- **Guardrails**: Explicit restrictions preventing destructive operations like deployments, service restarts, or configuration changes

This ensures your AI assistant can help troubleshoot issues on your servers without accidentally running commands that could affect production stability.
