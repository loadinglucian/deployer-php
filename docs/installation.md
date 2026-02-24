# Installation

<!-- toc -->

- [Install DeployerPHP](#install-deployerphp)
- [Requirements](#requirements)
- [The Commands](#the-commands)
- [The Inventory](#the-inventory)
- [Configuration Paths](#configuration-paths)
- [Command Replays](#command-replays)
- [Next Steps](#next-steps)

<!-- /toc -->

This guide is your starting point for understanding DeployerPHP. It walks you through the main operational concepts so you can move forward with the right mental model.

<a name="install-deployerphp"></a>

## Install DeployerPHP

Install DeployerPHP like any other Composer package:

```shell
# Install as a dev dependency
composer require --dev loadinglucian/deployer-php

# Add an alias for convenience
alias deployer="./vendor/bin/deployer"

# I use it a lot, so I prefer to shorten it even more
alias dep="deployer"
```

> [!NOTE]
> Add the alias to your shell profile (`~/.bashrc`, `~/.zshrc`) to make it permanent.

<a name="requirements"></a>

## Requirements

DeployerPHP has some pretty basic requirements:

- At least PHP 8.2
- The `pcntl` PHP extension (if you want to use the `server:ssh` command)

Your target servers should run Ubuntu LTS >= 24.04 (no interim releases like 25.04).

<a name="the-commands"></a>

## The Commands

DeployerPHP has a lot of commands and capabilities. There are quite a few, which can feel a bit daunting at first. The key is not to try to remember what each one is called or what it does but rather how they're all organized.

Run the `list` command to see all the available commands:

```shell
deployer list
```

Commands are organized into namespaces that represent what each group manages:

- **`server:*`**: Add, install, delete, and manage servers
- **`site:*`**: Create, deploy, delete, and manage sites
- **`cron:*`** and **`supervisor:*`**: Scheduled tasks and background processes
- **`nginx:*`** and **`php:*`**: Web server and PHP-FPM control
- **`mariadb:*`**, **`postgresql:*`**: Database services
- **`memcached:*`**, **`redis:*`**: Cache services
- **`scaffold:*`**: Generate cron, hook, supervisor, and AI skills config files
- **`aws:*`**, **`cf:*`**, **`do:*`**: Cloud provider integrations

For namespace-by-namespace behavior details, see the [Documentation Index](documentation.md) and its command reference sections.

<a name="the-inventory"></a>

## The Inventory

DeployerPHP tracks your servers and sites in an inventory file, which it initializes in your current working directory as `.deployer/inventory.yml`. This inventory file stores the details of servers you add and sites you create, so you don't have to re-enter connection details, domain names or IPs each time you run a command.

Commands automatically reference the inventory, making it easy to manage multiple servers or sites. This file does not contain any sensitive information, so it is safe to commit to version control.

<a name="configuration-paths"></a>

## Configuration Paths

Besides the inventory, commands also automatically reference the `.env` file in your current working directory if it exists.

Running any DeployerPHP command should display which environment or inventory files are being actively referenced right at the top:

```EXAMPLE nocopy
▒ ≡ DeployerPHP ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
▒
▒ Ver: ...
▒ Env: ~/example.com/.env
▒ Inv: ~/example.com/.deployer/inventory.yml
.
.
.
```

By default, DeployerPHP looks for both files in your current working directory. This works well for most projects, but you may need a little more flexibility when managing multiple environments or working from different directories.

Every command accepts two global options for overriding these paths:

- **`--env`** - Specify a custom path to your `.env` file
- **`--inventory`** - Specify a custom path to your inventory file

This is particularly useful when you:

- Manage separate staging and production inventories
- Run commands from a directory other than your project root
- Keep environment files in a centralized location
- Use different cloud provider credentials for different environments

For example, you might maintain `deployer-staging.yml` and `deployer-production.yml` in the same project, then use `--inventory` to target the appropriate environment.

<a name="command-replays"></a>

## Command Replays

Every DeployerPHP command prints a non-interactive replay at the end of its run. This replay shows the exact command with all of your prompt responses filled in as CLI options:

```EXAMPLE nocopy
Non-interactive command replay:
───────────────────────────────────────────────────────────────────────────
$> deployer server:add  \
  --name='web1' \
  --host='123.456.789.123' \
  --port='22' \
  --username='root' \
  --private-key-path='~/.ssh/id_ed25519'
```

You can copy this block directly into scripts or CI pipelines. You can also run any command with only some options filled in and answer the rest interactively, and the replay always reflects exactly what you chose, making it easy to learn the full CLI syntax by doing.

<a name="next-steps"></a>

## Next Steps

With the core concepts in place, the best next move is to run through your first real deployment workflow. For more information, see [Zero to Deploy](zero-to-deploy.md).
