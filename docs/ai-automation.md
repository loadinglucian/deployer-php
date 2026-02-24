# AI Automation

<!-- toc -->

- [Scaffolding the SKILL](#scaffolding-the-skill)
- [Permission Tiers](#permission-tiers)
    - [Observer](#observer)
    - [Debugger](#debugger)
    - [Admin](#admin)
- [Next Steps](#next-steps)

<!-- /toc -->

You can automate and significantly speed up the debugging process by letting an AI agent use the triage tools instead of using them manually. DeployerPHP can generate a SKILL file that gives your AI agent structured, permission-controlled access to your infrastructure, so it can read logs, inspect server state, and diagnose issues the same way you would.

This guide covers generating that agent SKILL and choosing the right permission tier for your workflow.

> [!IMPORTANT]
> Always review any AI SKILL carefully before using it and make sure you understand the risks of allowing an LLM to control which information is read from and what commands are executed on your servers.

<a name="scaffolding-the-skill"></a>

## Scaffolding the SKILL

Run the `scaffold:ai` command from your project directory:

```shell
deployer scaffold:ai
```

The command detects which AI agent directories already exist in your project. If one or more are found, it scaffolds into all of them automatically. If none exist, it prompts you with a multi-select so you can choose one or both. The supported directories are:

- **`.agents/`** - For Codex, Cursor, and OpenCode
- **`.claude/`** - For Claude Code

DeployerPHP will then prompt you for a permission tier and generate the SKILL inside a `skills/` subdirectory. Existing files are skipped by default, so you'll need to explicitly request an overwrite to regenerate. You can always rerun the command and use the non-interactive command replay to target specific directories.

This SKILL gives your AI agent all the context it needs to work with your DeployerPHP setup: knowledge of your inventory structure, your deployment layout, and a scoped set of commands matched to the permission tier you choose.

<a name="permission-tiers"></a>

## Permission Tiers

When generating your skills file, you'll choose a permission tier. Each tier defines what your AI agent can do on your servers:

<a name="observer"></a>

### Observer

The Observer tier gives your AI agent read-only access. It can run the `server:info` command to get a full picture of server health and the `server:logs` command to pull targeted logs.

The log scope covers service logs like nginx, PHP-FPM, databases, supervisor, and cron, per-site access and error logs, and aggregate views across all sites or workers. The `server:run` command isn't available at this tier, so there's no arbitrary shell execution.

> [!NOTE]
> The Observer tier is ideal for monitoring and log tracing scenarios where you want full visibility but with guardrails against the possibility of the AI agent running commands on your servers.

<a name="debugger"></a>

### Debugger

The Debugger tier builds on Observer and adds the ability to run safe, non-destructive shell commands via the `server:run` command. Your assistant can inspect release structure and symlinks, check service health, measure capacity metrics, tail application logs, and query PHP runtime configuration.

Two categories are off-limits: state-changing commands (deploy, install, restart, delete, and similar) and interactive terminal programs like `less`, `top`, `vim`, or nested `ssh`.

> [!NOTE]
> The Debugger tier enables AI agents to run complex investigation workflows for testing root-cause hypotheses while maintaining guardrails against unwanted side effects like data modification or downtime.

<a name="admin"></a>

### Admin

The Admin tier covers the full range of DeployerPHP command domains: server management, site lifecycle, cron, and supervisor. It also includes service installs and lifecycle management plus cloud provider integrations for provisioning, DNS, and SSH key management.

> [!IMPORTANT]
> While guardrails to prevent potentially unwanted side effects are provided, this tier is regarded as the most risky to operate within. Make sure you understand the risks of allowing an LLM this much access to your servers.

<a name="next-steps"></a>

## Next Steps

With AI automation configured, your agent can triage server and deployment issues using the same tools you use. For a complete list of available commands, see the command and cloud provider references.
