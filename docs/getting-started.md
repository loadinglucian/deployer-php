# Getting Started

DeployerPHP streamlines the process of managing, installing, and deploying your servers and sites.

This guide will walk you through setting up a new server and deploying your first site.

- [Installation](#installation)
- [Requirements](#requirements)
- [How Commands Are Organized](#how-commands-are-organized)
- [Step 1: Adding a Server](#add-new-server)
- [Step 2: Installing a Server](#install-new-server)

<a name="installation"></a>

## Installation

You can install DeployerPHP via Composer as either a global dependency or a per-project dependency:

```shell
# Global installation (recommended)
composer global require loadinglucian/deployer-php

# Or as a project dependency
composer require loadinglucian/deployer-php
```

> [!NOTE]
> If installing globally, make sure that Composer's global bin directory is included in your system's PATH. On **macOS** and **Linux**, this is typically located in `$HOME/.composer/vendor/bin`, while on **Windows**, it should be found in `%USERPROFILE%\AppData\Roaming\Composer\vendor\bin`.

If installed globally, you can run DeployerPHP from anywhere:

```shell
deployer list
```

If installed as a project dependency, you can run DeployerPHP via the vendor bin inside your project:

```shell
./vendor/bin/deployer list
```

<a name="requirements"></a>

## Requirements

DeployerPHP has some pretty basic minimum requirements:

- PHP 8.2 or higher
- The `pcntl` PHP extension (only if you want to use the `server:ssh` command)

Your target servers should be running a supported Linux distribution:

- Debian 11 or newer
- Ubuntu 22.04 LTS or newer

<a name="how-commands-are-organized"></a>

## How Commands Are Organized

DeployerPHP commands are grouped by what they manage:

- **`server:*`** — Add, install, delete, and SSH into servers
- **`site:*`** — Create, deploy, delete, and manage sites
- **`scaffold:*`** — Generate cron, hook, and supervisor config files
- **`cron:*`** and **`supervisor:*`** — Scheduled tasks and background processes
- **`nginx:*`** and **`php:*`** — Web server and PHP-FPM control
- **`mariadb:*`**, **`mysql:*`**, **`postgresql:*`** — Database services
- **`memcached:*`**, **`redis:*`**, **`valkey:*`** — Cache services
- **`pro:*`** — Cloud provider integrations (AWS, DigitalOcean)

<a name="add-new-server"></a>

## Step 1: Adding a Server

First, add your new server to the inventory by running the `server:add` command:

```shell
deployer server:add
```

> [!NOTE]
> DeployerPHP will initialize an empty inventory in your current working directory. The inventory is a simple `deployer.yml` file that DeployerPHP uses to keep track of your servers and sites. Typically, you should run DeployerPHP from your project directory. If a .env file exists in your current working directory, DeployerPHP will also use that.

DeployerPHP will prompt you for:

- **Server name** - A friendly name for your server (e.g., "production", "web1")
- **Host** - The IP address or hostname of your server
- **Port** - SSH port (default: 22)
- **Username** - SSH username (default: root)
- **Private key path** - Path to the SSH private key used to connect to the server

Once completed, DeployerPHP confirms the connection and adds the server to your inventory. You can then run `server:info` to view server details or `server:install` to set up the server.

> [!NOTE]
> After each command, DeployerPHP displays a non-interactive command replay that includes all the options you selected. You can copy this command to repeat or automate the operation.

> [!NOTE]
> You can use the `pro:aws:provision` or `pro:do:provision` commands to automatically provision and add a new EC2 instance or droplet to your inventory. It's super convenient if you want to spin up servers on the fly in your automation pipelines.

### Delete A Server From Inventory

To delete a server from the inventory, run the `server:delete` command:

```shell
deployer server:delete
```

DeployerPHP will prompt you to select a server, type its name to confirm, and give final confirmation before deletion.

> [!WARNING]
> You are responsible for making sure the server is no longer running or incurring costs with your hosting provider.

> [!NOTE]
> If you used the `pro:aws:provision` or `pro:do:provision` commands to provision the server, the `server:delete` command will automatically destroy the cloud instance for you.

<a name="install-new-server"></a>

## Step 2: Installing a Server

Second, install and configure your new server by running the `server:install` command:

```shell
deployer server:install
```

DeployerPHP will prompt you for:

- **Server** - Select from your inventory
- **PHP version** - The version of PHP you want to install
- **PHP extensions** - The PHP extensions you want to install
- **Deploy key** - The SSH key used to access your repositories

The installation process will:

1. Update package lists and install base packages
2. Configure Nginx with a monitoring endpoint
3. Set up the firewall (UFW)
4. Install your chosen PHP version with selected extensions
5. Install Composer and Bun
6. Create a `deployer` user for deployments
7. Generate an SSH key pair for repository access

> [!NOTE]
> After installation, the command displays the server's public key. Add this key to your Git provider to enable access to your repositories.

### Install Multiple PHP Versions

You can install multiple PHP versions, each with its own set of extensions, by running the `server:install` command again at any time, even after deploying multiple sites.

> [!NOTE]
> When you install multiple PHP versions, you can pick which one should be the default CLI version for your server, and then choose which version each site should use when you deploy it.
