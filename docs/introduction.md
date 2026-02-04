# Introduction

<!-- toc -->

- [Installation](#installation)
- [Requirements](#requirements)
- [The Commands](#the-commands)
- [The Inventory](#the-inventory)
- [Configuration Paths](#configuration-paths)
- [Command Replays](#command-replays)

<!-- /toc -->

This is DeployerPHP, a set of command-line interface (CLI) tools for provisioning, installing, and deploying servers and sites using PHP. It serves as an open-source alternative to services like Laravel Forge and Ploi.

## Installation

DeployerPHP is built around Symfony Console and comes bundled as a Composer package so you can easily install and use it as part of your existing workflow:

```shell
# Install as a dev dependency
composer require --dev loadinglucian/deployer-php

# Add an alias for convenience
alias deployer="./vendor/bin/deployer"
```

> [!TIP]
> Add the alias to your shell profile (`~/.bashrc`, `~/.zshrc`) to make it permanent.

## Requirements

DeployerPHP has some basic requirements:

- At least PHP 8.2
- The `pcntl` PHP extension (if you want to use the `server:ssh` command)

Your target servers should run a supported Linux distribution:

- Ubuntu LTS (such as 24.04, 26.04, etc., no interim releases like 25.04)
- Debian 12 or newer

## The Commands

Once installed, run the `list` command to see all the other available commands:

```shell
deployer list
```

DeployerPHP has a wide range of commands and capabilities. All commands are organized into namespaces that represent what each group manages:

- **`server:*`**: Add, install, delete, and manage servers
- **`site:*`**: Create, deploy, delete, and manage sites
- **`cron:*`** and **`supervisor:*`**: Scheduled tasks and background processes
- **`nginx:*`** and **`php:*`**: Web server and PHP-FPM control
- **`mariadb:*`**, **`mysql:*`**, **`postgresql:*`**: Database services
- **`memcached:*`**, **`redis:*`**, **`valkey:*`**: Cache services
- **`scaffold:*`**: Generate cron, hook, supervisor, and AI skills config files
- **`aws:*`**, **`cf:*`**, **`do:*`**: Cloud provider integrations

Don't worry about what each of these does. For now, just focus on how everything is laid out and organized. We'll cover each of them in detail in other sections of the documentation.

## The Inventory

DeployerPHP tracks your servers and sites in a `.deployer/inventory.yml` file, which it initializes in your current working directory. This inventory file stores the details of servers you add and sites you create, so you don't have to re-enter connection details each time you run a command.

Commands automatically reference the inventory, making multi-server management straightforward. You can commit this file to version control to share your infrastructure configuration with your team.

## Configuration Paths

DeployerPHP uses two configuration files to manage your infrastructure:

- **`.env`** - Environment variables like API keys for cloud providers
- **`.deployer/inventory.yml`** - The inventory file that stores your servers and sites

```DeployerPHP
▒ ≡ DeployerPHP ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
▒
▒ Ver: dev-main
▒ Env: ~/example.com/.env
▒ Inv: ~/example.com/.deployer/inventory.yml
.
.
.
```

By default, DeployerPHP looks for both files in your current working directory. This works well for most projects, but you may need more flexibility when managing multiple environments or working from different directories.

If you have an existing `deployer.yml`, move it to `.deployer/inventory.yml` or run commands with `--inventory` to target the legacy path.

Every command accepts two global options for overriding these paths:

- **`--env`** - Specify a custom path to your `.env` file
- **`--inventory`** - Specify a custom path to your inventory file

This is particularly useful when you:

- Manage separate staging and production inventories
- Run commands from a directory other than your project root
- Keep environment files in a centralized location
- Use different cloud provider credentials for different environments

For example, you might maintain `deployer-staging.yml` and `deployer-production.yml` in the same project, then use `--inventory` to target the appropriate environment.

## Command Replays

Every DeployerPHP command provides a non-interactive command replay at the end of execution. This replay displays the exact command along with all your interactive prompt responses filled in:

```DeployerPHP nocopy
.
.
.
Non-interactive command replay:
───────────────────────────────────────────────────────────────────────────
$> deployer server:add  \
  --name='web1' \
  --host='123.456.789.123' \
  --port='22' \
  --username='root' \
  --private-key-path='~/.ssh/id_ed25519'
```

You can copy these replays directly into scripts or CI pipelines to automate your workflow.

Read [Zero to Deploy](/docs/zero-to-deploy) next to get started with your first deployment!
