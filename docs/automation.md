# Automation

<!-- toc -->

- [Command Replays](#command-replays)
- [Quiet Mode](#quiet-mode)
- [AI Automation](#ai-automation)

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

If you use AI tools like Claude, Cursor, or Codex, you can create a rules file that guides agents on safely interacting with your DeployerPHP-managed servers. This is useful when debugging issues with your application in production. Agents can read logs or execute remote, non-destructive commands on your server to investigate and resolve problems.

> [!WARNING]
> **Use at your own risk!** Granting AI agents access to production servers can be risky. Always review generated rules and monitor AI-initiated actions. You are solely responsible for any changes, data loss, or issues arising from AI-assisted debugging.

Run the `scaffold:ai` command from your project directory:

```shell
deployer scaffold:ai
```

DeployerPHP will prompt you to select your AI agent:

- **Claude**: Creates rules in `.claude/rules/`
- **Cursor**: Creates rules in `.cursor/rules/`
- **Codex**: Creates rules in `.codex/rules/`

> [!NOTE]
> If an existing AI agent directory is detected in your project, DeployerPHP will automatically use it. If multiple are found, you'll be prompted to choose one.

The generated rules file provides your AI assistant with:

- **Inventory context**: Understanding of your `deployer.yml` structure
- **Deployment layout**: Knowledge of the release directory structure
- **Safe debugging commands**: Commands for viewing logs, checking status, and reading files
- **Guardrails**: Explicit restrictions preventing destructive operations like deployments, service restarts, or configuration changes

This ensures your AI assistant can help troubleshoot issues on your servers without accidentally running commands that could affect production stability.
