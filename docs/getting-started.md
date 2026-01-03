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

> [!NOTE]
> Your server should be running either Debian 11 or newer, or Ubuntu 22.04 LTS or newer.

DeployerPHP will then confirm the connection and add your server to the inventory:

```shell
❯ deployer server:add

▒ ▶ DeployerPHP ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
▒ Ver: dev-main
▒ Env: ~/Developer/your-project/.env
▒ Inv: ~/Developer/your-project/deployer.yml
▒
▒ # Add New Server
▒ ────────────────────────────────────────────────────────────────────────────

 ┌ Server name: ────────────────────────────────────────────────┐
 │ web1                                                         │
 └──────────────────────────────────────────────────────────────┘

 ┌ Host/IP address: ────────────────────────────────────────────┐
 │ 123.456.789.123                                              │
 └──────────────────────────────────────────────────────────────┘

 ┌ SSH port: ───────────────────────────────────────────────────┐
 │ 22                                                           │
 └──────────────────────────────────────────────────────────────┘

 ┌ SSH username: ───────────────────────────────────────────────┐
 │ root                                                         │
 └──────────────────────────────────────────────────────────────┘

 ┌ Path to SSH private key (leave empty for default ~/.ssh/id_ed25519 or ~/.ssh/id_rsa): ┐
 │                                                                                       │
 └───────────────────────────────────────────────────────────────────────────────────────┘

▒ Name: web1
▒ Host: 123.456.789.123
▒ Port: 22
▒ User: root
▒ Key:  ~/.ssh/id_ed25519
▒ ───
▒ ✓ Server added to inventory
▒ • Run server:info to view server information
▒ • Or run server:install to install your new server

Non-interactive command replay:
──────────────────────────────────────────────────────────────────────────────
$> deployer server:add  \
  --name='web1' \
  --host='123.456.789.123' \
  --port='22' \
  --username='root' \
  --private-key-path='~/.ssh/id_ed25519'
```

> [!NOTE]
> DeployerPHP shows a non-interactive command replay that includes all the prompts you entered, allowing you to easily repeat or automate the operation as needed.

> [!NOTE]
> You can use the `pro:aws:provision` or `pro:do:provision` commands to automatically provision and add a new EC2 instance or droplet to your inventory. It's super convenient if you want to spin up servers on the fly in your automation pipelines.

### Delete A Server From Inventory

To delete a server from the inventory run the `server:delete` command.

DeployerPHP will prompt you for:

- **Server** - Select from your inventory
- **Confirmation** - Type the server name to confirm deletion
- **Final confirmation** - Confirm you want to proceed with deletion

```shell
▒ ▶ DeployerPHP ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
▒ Ver: dev-main
▒ Env: /Users/lucian/Developer/deployer-php/.env
▒ Inv: /Users/lucian/Developer/deployer-php/deployer.yml
▒
▒ # Delete Server
▒ ────────────────────────────────────────────────────────────────────────────

 ┌ Select server: ──────────────────────────────────────────────┐
 │ web1                                                         │
 └──────────────────────────────────────────────────────────────┘

▒ Name: web1
▒ Host: 157.230.100.82
▒ Port: 22
▒ User: root
▒ Key:  /Users/lucian/.ssh/id_ed25519
▒ ───
▒ ℹ This will:
▒ • Remove the server from inventory
▒ • Destroy the droplet on DigitalOcean (ID: 540300798)

 ┌ Type the server name 'web1' to confirm deletion: ────────────┐
 │ web1                                                         │
 └──────────────────────────────────────────────────────────────┘

 ┌ Are you absolutely sure? ────────────────────────────────────┐
 │ Yes                                                          │
 └──────────────────────────────────────────────────────────────┘

▒ ✓ Droplet destroyed (ID: 540300798)
▒ ✓ Server 'web1' removed from inventory

Non-interactive command replay:
────────────────────────────────────────────────────────────────────────────
$> deployer server:delete  \
  --server='web1' \
  --force \
  --yes
```

> [!NOTE]
> You are responsible for making sure the server is no longer running or incurring costs with your hosting provider.

> [!NOTE]
> If you used the `pro:aws:provision` or `pro:do:provision` commands to provision the server, the `server:delete` command will automatically destroy the cloud instance for you. It's super convenient if you want to spin up and spin down servers on the fly after your automation pipelines finish.

<a name="install-new-server"></a>

## Step 2: Installing a Server

Second, install and configure your new server by running the `server:install` command:

```shell
deployer server:install
```

> [!NOTE]
> This command will set up your server with all the Nginx and PHP bells and whistles you need to deploy your PHP applications.

DeployerPHP will prompt you for:

- **Server** - Select from your inventory
- **PHP version** - The version of PHP you want to install
- **PHP extensions** - The PHP extensions you want to install
- **Deploy key** - The SSH private key used to access your repositories

```shell
deployer server:install

▒ ▶ DeployerPHP ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
▒ Ver: dev-main
▒ Env: ~/Developer/your-project/.env
▒ Inv: ~/Developer/your-project/deployer.yml
▒
▒ # Install Server
▒ ────────────────────────────────────────────────────────────────────────────

 ┌ Select server: ──────────────────────────────────────────────┐
 │ web1                                                         │
 └──────────────────────────────────────────────────────────────┘

▒ Name: web1
▒ Host: 123.456.789.123
▒ Port: 22
▒ User: root
▒ Key:  ~/.ssh/id_ed25519
▒ ───
▒ $> Preparing packages...
→ Updating package lists...
...
→ Detecting available PHP versions...
→ Detecting available PHP extensions...
▒ ───
▒ $> Installing base packages...
→ Installing packages...
...
→ Setting up Nginx base config...
→ Creating monitoring endpoint...
...
→ Reloading Nginx configuration...
→ Configuring firewall...
▒ ───

 ┌ PHP version: ────────────────────────────────────────────────┐
 │ 8.5                                                          │
 └──────────────────────────────────────────────────────────────┘

 ┌ Select PHP extensions: ──────────────────────────────────────┐
 │ bcmath                                                       │
 │ cli                                                          │
 │ common                                                       │
 │ curl                                                         │
 │ fpm                                                          │
 │ gd                                                           │
 │ gmp                                                          │
 │ igbinary                                                     │
 │ imagick                                                      │
 │ imap                                                         │
 │ intl                                                         │
 │ mbstring                                                     │
 │ memcached                                                    │
 │ msgpack                                                      │
 │ mysql                                                        │
 │ pgsql                                                        │
 │ readline                                                     │
 │ redis                                                        │
 │ soap                                                         │
 │ sqlite3                                                      │
 │ xml                                                          │
 │ zip                                                          │
 └──────────────────────────────────────────────────────────────┘

▒ $> Installing PHP...
→ Installing PHP 8.5...
...
→ Configuring PHP-FPM for PHP 8.5...
→ Setting up PHP-FPM logrotate...
→ Setting PHP 8.5 as system default...
→ Installing Composer...
...
→ Adding PHP 8.5 status endpoint to Nginx...
...
▒ ───
▒ $> Installing Bun...
→ Installing Bun...
...
▒ ───

 ┌ Deploy key: ─────────────────────────────────────────────────┐
 │ Use server-generated key pair                                │
 └──────────────────────────────────────────────────────────────┘

▒ $> Setting up deployer user...
→ Creating deployer user...
→ Adding www-data to deployer group...
→ Restarting Nginx to apply group membership...
→ Restarting PHP-FPM services...
→ Creating /home/deployer/.ssh directory...
→ Generating SSH key pair...
...
▒ ───
▒ ✓ Server installation completed successfully
▒ • Run site:create to create a new site
▒ • View server and service info with server:info
▒ • Add the following public key to your Git provider (GitHub, GitLab, etc.) to enable deployments:

ssh-ed25519 ... deployer@web1

↑ IMPORTANT: Add this public key to your Git provider to enable access to your repositories.

Non-interactive command replay:
────────────────────────────────────────────────────────────────────────────
$> deployer server:install  \
  --server='web1' \
  --php-version='8.5' \
  --php-extensions='bcmath,cli,common,curl,fpm,gd,gmp,igbinary,imagick,imap,intl,mbstring,memcached,msgpack,mysql,pgsql,readline,redis,soap,sqlite3,xml,zip' \
  --generate-deploy-key
```

> [!NOTE]
> After installation, add the highlighted public key to your Git provider to gain access to your repositories.

### Install Multiple PHP Versions

You can install multiple PHP versions, each with its own set of extensions, by running the `server:install` command again at any time, even after deploying multiple sites.

> [!NOTE]
> When you install multiple PHP versions, you can pick which one should be the default CLI version for your server, and then choose which version each site should use when you deploy it.
