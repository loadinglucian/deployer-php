# Getting Started

DeployerPHP streamlines the process of managing, installing, and deploying your servers and sites.

This guide will walk you through setting up a new server and deploying your first site.

- [Installation](#installation)
- [Requirements](#requirements)
- [How Commands Are Organized](#how-commands-are-organized)
- [Step 1: Adding a Server](#add-new-server)
- [Step 2: Installing a Server](#install-new-server)
- [Step 3: Creating a Site](#create-new-site)
- [Step 4: Deploying a Site](#deploy-site)
- [Step 5: Enabling HTTPS](#enable-https)

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
> If you have multiple PHP versions installed on the server, you can choose which version each site should use. This is useful when running multiple applications with different PHP requirements on the same server.

<a name="create-new-site"></a>

## Step 3: Creating a Site

Now that your server is installed, you're ready to create your first site. Run the `site:create` command:

```shell
deployer site:create
```

DeployerPHP will prompt you for:

- **Server** - Select the server to host your site
- **Domain name** - Your site's domain (e.g., "example.com")
- **WWW handling** - Whether to redirect www to non-www (or vice versa)
- **PHP version** - The PHP version to use for this site

The creation process will:

1. Create the site directory structure on your server
2. Configure Nginx for your domain
3. Add the site to your inventory

Once completed, DeployerPHP displays the next steps:

- Point your DNS records (both `@` and `www`) to your server's IP address
- Run `site:https` to enable HTTPS once DNS propagates
- Run `site:deploy` to deploy your application

### Delete a Site

To delete a site from a server, run the `site:delete` command:

```shell
deployer site:delete
```

DeployerPHP will prompt you to select a site, type its name to confirm, and give final confirmation before deletion.

<a name="deploy-site"></a>

## Step 4: Deploying a Site

Before you can deploy, you'll need deployment hooks in your project repository. DeployerPHP uses a hook-based deployment system that gives you full control over the build and release process.

### Scaffold Deployment Hooks

From your project directory, run the `scaffold:hooks` command to generate the deployment hooks:

```shell
deployer scaffold:hooks
```

This creates three hook scripts in your project's `.deployer/hooks/` directory:

- **1-building.sh** - Runs after cloning. Installs Composer dependencies and builds frontend assets
- **2-releasing.sh** - Runs before activation. Handles migrations, shared storage, and framework-specific optimizations
- **3-finishing.sh** - Runs after the new release is live. Use for cleanup or notifications

> [!NOTE]
> The generated hooks include sensible defaults for modern PHP frameworks such as Laravel, Symfony, and CodeIgniter. Review and customize them for your specific application needs, then commit them to your repository.

### Deploy Your Site

Once your hooks are committed and pushed, run the `site:deploy` command:

```shell
deployer site:deploy
```

DeployerPHP will prompt you for:

- **Site** - Select the site to deploy
- **Git repository URL** - Your repository's SSH URL (auto-detected from your local git remote)
- **Branch** - The branch to deploy (auto-detected from your current branch)
- **Confirmation** - Final confirmation before deployment

The deployment process will:

1. Clone your repository to a new release directory
2. Run `1-building.sh` to install dependencies and build assets
3. Link shared resources (`.env`, `storage/`, etc.) into the release
4. Run `2-releasing.sh` to prepare the release (migrations, caching)
5. Activate the new release by updating the `current` symlink
6. Run `3-finishing.sh` for any post-deployment tasks
7. Reload PHP-FPM to pick up the new code
8. Clean up old releases (keeps the last 5 by default)

> [!NOTE]
> DeployerPHP uses a release-based deployment strategy. Each deployment creates a new release directory, and a `current` symlink points to the active release. This allows for instant rollbacks and zero-downtime deployments.

### Upload Shared Files

After your first deployment, you'll need to upload environment files and other shared data that shouldn't be in version control:

```shell
deployer site:shared:push
```

This uploads files from your local `.deployer/shared/` directory to the server's `shared/` directory. The shared directory persists across deployments—place your `.env` file here.

<a name="enable-https"></a>

## Step 5: Enabling HTTPS

Once your DNS records are pointing to your server and have propagated, you can enable HTTPS using Let's Encrypt certificates:

```shell
deployer site:https
```

DeployerPHP will prompt you to select a site, then automatically:

1. Install Certbot if not already present
2. Obtain an SSL certificate from Let's Encrypt
3. Configure Nginx for HTTPS with proper redirects
4. Set up automatic certificate renewal

> [!WARNING]
> Make sure your DNS records are properly configured and have propagated before running this command. Let's Encrypt validates domain ownership by making HTTP requests to your server, which will fail if DNS isn't pointing to your server yet.

After HTTPS is enabled, your site will automatically redirect HTTP traffic to HTTPS.
