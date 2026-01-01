# Getting Started

This guide will walk you through setting up a new server and deploying your first application with DeployerPHP.

- [Installation](#installation)
- [Requirements](#requirements)
- [Where Everything Is](#where-everything-is)
- [Setting Up A New Server](#setting-up-new-server)
    - [Step 1: Add The New Server To Inventory](#add-new-server)
    - [Delete A Server From Inventory](#delete-server)
    - [Step 2: Install The New Server](#install-new-server)

<a name="installation"></a>

## Installation

You can install DeployerPHP via Composer as either a global dependency or a per-project dependency:

```bash
# Global installation (recommended)
composer global require loadinglucian/your-project

# Or as a project dependency
composer require loadinglucian/your-project
```

> [!NOTE]
> If installing globally, make sure that Composer's global bin directory is included in your system's PATH. On **macOS** and **Linux**, this is typically located in `$HOME/.composer/vendor/bin`, while on **Windows**, it should be found in `%USERPROFILE%\AppData\Roaming\Composer\vendor\bin`.

If installed globally, you can run DeployerPHP from anywhere:

```bash
deployer list
```

If installed as a project dependency, you can run DeployerPHP via the vendor bin inside your project:

```bash
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

<a name="where-everything-is"></a>

## Where Everything Is

DeployerPHP is organized into several command groups.

You've got your `server:*` commands that deal with server stuff:

```bash
deployer server:add         # Add The New Server To Inventory
         server:delete      # Delete a server from inventory
         server:firewall    # Manage UFW firewall rules on the server
         server:info        # Display server information
         server:install     # Install the server so it can host PHP applications
         server:logs        # View server logs (system, services, sites, and supervisors)
         server:run         # Run arbitrary command on a server
         server:ssh         # SSH into a server
```

You've got your `site:*` commands that deal with site stuff:

```bash
deployer site:create        # Create a new site on a server and add it to inventory
         site:delete        # Delete a site from a server and remove it from inventory
         site:deploy        # Deploy a site by running the deployment playbook and hooks
         site:https         # Enable HTTPS for a site using Certbot
         site:logs          # View site logs (access, crons, and supervisors)
         site:rollback      # Learn about forward-only deployments
         site:shared:pull   # Download a file from a site's shared directory
         site:shared:push   # Upload a file into a site's shared directory
         site:ssh           # SSH into a site directory
```

And then you've got your service commands like `scaffold:*`, `cron:*`, `supervisor:*`, `nginx:*`, `php:*`, your database commands like `mariadb:*`, `mysql:*` and `postgresql:*`, your cache commands like `memcached:*`, `redis:*` and `valkey:*` and then you've got your `pro:*` commands for third-party API integrations with popular hosting providers.

Don't worry about what everything does right now.

This is just so you know where everything is.

<a name="setting-up-server"></a>

## Setting Up A New Server

<a name="add-new-server"></a>

### Step 1: Add The New Server To Inventory

First, add your new server to the inventory by running the `server:add` command:

```bash
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

```bash
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
> DeployerPHP displays a copy-paste-ready, non-interactive command replay with all the prompts you entered. This lets you automate or repeat the operation if you need to.

> [!PRO]
> You can use the `pro:aws:provision` or `pro:do:provision` commands to automatically provision and add a new EC2 instance or droplet to your inventory. It's super convenient if you want to spin up servers on the fly in your automation pipelines.

#### Delete A Server From Inventory

To delete a server from the inventory run the `server:delete` command.

DeployerPHP will prompt you for:

- **Server** - Select from your inventory
- **Confirmation** - Type the server name to confirm deletion
- **Final confirmation** - Confirm you want to proceed with deletion

```bash
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

> [!PRO]
> If you used the `pro:aws:provision` or `pro:do:provision` commands to provision the server, the `server:delete` command will automatically destroy the EC2 instance or droplet for you. It's super convenient if you want to spin down servers on the fly after your automation pipelines finish.

<a name="install-new-server"></a>

### Step 2: Install The New Server

Second, install and configure your new server by running the `server:install` command:

```bash
deployer server:install
```

> [!NOTE]
> This command will set up your server with all the Nginx and PHP bells and whistles you need to deploy your PHP applications.

DeployerPHP will prompt you for:

- **Server** - Select from your inventory
- **PHP version** - The version of PHP you want to install
- **PHP extensions** - The PHP extensions you want to install
- **Deploy key** - The SSH private key used to access your repositories

```bash
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

Don't worry about what PHP version or extensions you choose to install. You can always run the command again at any time (even after deploying multiple sites) to install extra PHP versions and extensions, they'll all work in parallel.

> [!NOTE]
> You can select which version of PHP you want to use for each individual site you deploy.
