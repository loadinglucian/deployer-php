# Zero to Deploy

<!-- toc -->

- [Step 1: Add A Server](#step-1-add-a-server)
    - [Cloud Instances](#cloud-instances)
- [Step 2: Install The Server](#step-2-install-the-server)
    - [Additional PHP Versions](#additional-php-versions)
    - [Installing Databases](#installing-databases)
- [Step 3: Create a Site](#step-3-create-a-site)
    - [Managing DNS](#managing-dns)
- [Step 4: Deploy a Site](#step-4-deploy-a-site)
    - [The Deployment Lifecycle](#the-deployment-lifecycle)
    - [Deployment Scripts](#deployment-scripts)
    - [Shared Files](#shared-files)
    - [Release Management](#release-management)
- [Step 5: Enable HTTPS](#step-5-enable-https)
- [Next Steps](#next-steps)

<!-- /toc -->

This guide is going to walk you through deploying your first application with DeployerPHP. By the time we're done, DeployerPHP will have:

- Fully configured a server runtime environment with Nginx, PHP, Bun, and a dedicated deployment user with its own deploy key
- Set up additional PHP versions and your preferred database or cache services (MariaDB, PostgreSQL, Redis, or Memcached)
- Created a deploy-ready site structure with releases, shared resources, and zero downtime deployment support
- Deployed your application from Git using atomic deployment scripts for build, migration, cron, and worker workflows
- Enabled Let's Encrypt HTTPS and automatic renewal with guided DNS setup and verification for your domain

All you have to do is run a few simple commands and respond to a couple of interactive prompts. DeployerPHP will take care of all the hard stuff.

## Step 1: Add A Server

Before we can deploy anything we'll need a server to deploy to. You can use any physical server, VPS, or cloud instance as long as you can SSH into it and it runs Ubuntu LTS >= 24.04 (no interim releases like 25.04).

Run the `server:add` command to add a new server to your inventory:

```shell
deployer server:add
```

The command will ask for your server details, including the host/IP, SSH port, username, key, and a name for your new server. It will try connecting to the server and then confirm adding your server to the inventory:

```DeployerPHP nocopy
▒ ≡ DeployerPHP ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
▒
.
.
.
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
───────────────────────────────────────────────────────────────────────────
$> deployer server:add  \
  --name='web1' \
  --host='123.456.789.123' \
  --port='22' \
  --username='root' \
  --private-key-path='~/.ssh/id_ed25519'
```

For more information, see [Managing Servers](/docs/managing-servers)

### Cloud Instances

Alternatively, you can provision a cloud instance and add it to the inventory automatically using one of the dedicated cloud provider commands. For more information, see [Cloud Providers](/docs/cloud-providers).

## Step 2: Install The Server

To install your new server, run the `server:install` command. This will configure the runtime environment necessary to deploy and host your applications:

```shell
deployer server:install
```

This installs and configures your server runtime environment with:

- **Base packages** - git, curl, unzip, and essential utilities
- **System timezone** - Ensures consistent timestamps across services
- **Nginx** - Web server with optimized configuration
- **PHP** - Your selected version with extensions
- **Bun** - JavaScript runtime for building assets
- **Deployer user** - Dedicated user for deployments with deploy key

```DeployerPHP nocopy
▒ ≡ DeployerPHP ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
▒
.
.
.
▒ ───
▒ ✓ Server installation completed successfully
▒ • Run site:create to create a new site
▒ • View server and service info with server:info
▒ • Add the following public key to your Git provider (GitHub, GitLab, etc.) to enable deployments:

<the-key-will-be-displayed-here>

↑ IMPORTANT: Add this public key to your Git provider to enable access to your repositories.
.
.
.
```

> [!IMPORTANT]
> After installation, the command displays the deploy user's public key. Use this key to enable access to your application's repository through your Git provider.

### Additional PHP Versions

You can run the `server:install` command at any time to install additional PHP versions or different extensions.

The `server:install` command is additive, meaning it will always add new components without uninstalling anything. Any previous versions or extensions you installed will remain unchanged, so simply choose what you want to install now.

> [!INFO]
> When you have multiple PHP versions installed, the `server:install` command will prompt you for the default PHP version you want to use for your server CLI.

For more information, see [Managing Services](/docs/managing-services).

### Installing Databases

You can install your preferred database or cache services by running one of the dedicated installation commands:

| Command              | Description                        |
| -------------------- | ---------------------------------- |
| `mariadb:install`    | Install MariaDB database server    |
| `postgresql:install` | Install PostgreSQL database server |
| `redis:install`      | Install Redis key-value store      |
| `memcached:install`  | Install Memcached caching server   |

For more information, see [Managing Databases](/docs/managing-databases).

## Step 3: Create a Site

At this point, your server runtime environment should be installed and ready for your application. Run the `site:create` command to create a new site:

```shell
deployer site:create
```

This creates an Nginx configuration as well as a deploy-ready directory structure structure with releases, shared resources and current release symlink for zero downtime deployment support:

```EXAMPLE nocopy
/home/deployer/sites/example.com/
├── current -> releases/...   # Symlink to active release
├── releases/                 # Timestamped deployments
│   └── .../
├── repo/                     # Git repository clone
└── shared/                   # Persistent files (.env, storage, etc.)
```

### Managing DNS

Point your DNS to the server through your DNS provider:

- **A Record**: Point your domain to your server's IP address
- **AAAA records** (optional): Point your domain to your server's IPv6 addresses
- **CNAME Record** (optional): Point www subdomain to your main domain

DNS propagation typically takes a while, so the sooner you can get it out of the way, the better. Run the `deployer site:dns:check` command to check your DNS resolution:

```shell
deployer site:dns:check
```

If you use any of the supported DNS providers, you can configure your DNS using one of the dedicate provider commands:

| Command       | Description                                |
| ------------- | ------------------------------------------ |
| `aws:dns:set` | Create or update a AWS Route53 record      |
| `cf:dns:set`  | Create or update a Cloudflare DNS record   |
| `do:dns:set`  | Create or update a DigitalOcean DNS record |

For more information, see [Cloud Providers](/docs/cloud-providers).

## Step 4: Deploy a Site

Run the `site:deploy` command to deploy your application from a Git repository:

```shell
deployer site:deploy
```

DeployerPHP will prompt you for:

- **Repository URL** - The Git repository containing your application code
- **Branch** - The branch to deploy (e.g., `main`, `production`)

DeployerPHP uses a release-based deployment model that enables zero-downtime deployments. Instead of updating files in place, each deployment creates a new timestamped release directory. Once the release is fully prepared, the `current` symlink atomically switches to point to the new release. This atomic symlink swap means your application is never in a partially-updated state during deployment.

### The Deployment Lifecycle

Understanding the deployment lifecycle helps you write effective deployment scripts and troubleshoot issues. Here's what happens when you run `site:deploy`:

1. **Repository Setup** - On the first deployment, DeployerPHP clones your repository into the `repo/` directory. On subsequent deployments, it fetches the latest changes from the remote.

2. **Release Creation** - A new timestamped directory is created in `releases/` (e.g., `releases/20240115_143052`). Your code is exported from the repository into this directory using `git archive`, ensuring a clean copy without Git metadata.

3. **Deploy Script** - If your project has a `.deployer/scripts/deploy.sh` script, it runs now. This single script handles the entire pre-activation workflow: installing dependencies, building assets, linking shared resources, running migrations, and optimizing caches. The release is isolated at this point, so failures here won't affect your live site.

4. **Activation** - The `current` symlink atomically switches to point to the new release. This is the moment your new code goes live. The atomic nature of symlink operations means there's no "in-between" state.

5. **PHP-FPM Reload** - DeployerPHP reloads PHP-FPM to clear the opcode cache, ensuring PHP serves your new code immediately.

6. **Cleanup** - Old releases beyond the keep count (default: 5) are removed to free disk space.

### Deployment Scripts

Run the `scaffold:scripts` command in your project directory to scaffold your deployment scripts:

```shell
deployer scaffold:scripts
```

This creates `deploy.sh`, `cron.sh`, and `supervisor.sh` in the `.deployer/scripts` directory. The `deploy.sh` script handles the complete pre-activation workflow: installing dependencies, building assets, linking shared resources, running migrations, and optimizing caches. The `cron.sh` and `supervisor.sh` scripts are starting points for scheduled tasks and long-running workers.

Each script has access to these environment variables:

| Variable           | Description                           |
| ------------------ | ------------------------------------- |
| `DEPLOYER_RELEASE` | Path to the current release directory |
| `DEPLOYER_SHARED`  | Path to the shared directory          |
| `DEPLOYER_CURRENT` | Path to the current symlink           |
| `DEPLOYER_REPO`    | Path to the repository directory      |

The script runs in the release directory with the `deployer` user. Adding `set -e` at the top ensures the deployment stops if any command fails, preventing a broken release from going live.

> [!INFO]
> The deploy script is the ideal place to create shared directories your application needs. For example, if your application stores user uploads, create the directory with `mkdir -p "$DEPLOYER_SHARED_PATH/uploads"` and symlink it into the release.

### Shared Files

Shared files and directories persist across deployments. Common examples include `.env` configuration files, user-uploaded content, and SQLite databases. The deploy script links specific shared items into each release, giving you fine-grained control over what gets symlinked and how.

Use the `site:shared:push` command to upload a file to a site's shared directory:

```shell
deployer site:shared:push
```

DeployerPHP will prompt you for the server, site, local file path, and remote file path within the shared directory. Use `site:shared:pull` to download a shared file to your local machine.

> [!INFO]
> The `site:shared:*` commands support single files. Create directory structures your application needs in the deploy script.

### Release Management

Each deployment creates a new release directory with a timestamp in the format `YYYYMMDD_HHMMSS`. The `current` symlink always points to the active release.

By default, DeployerPHP keeps the 5 most recent releases. You can customize this when running the deploy command.

Keeping multiple releases enables quick rollbacks. If a deployment causes issues, you can manually switch back to a previous release by updating the `current` symlink to point to an older release directory and reloading PHP-FPM.

> [!INFO]
> You can view your releases by SSHing into the server and listing the `releases/` directory. The timestamps make it easy to identify when each deployment occurred.

## Step 5: Enable HTTPS

The `site:https` command installs an SSL certificate using Certbot:

```shell
deployer site:https
```

This:

1. Installs Certbot if not present
2. Obtains a Let's Encrypt certificate
3. Configures Nginx for HTTPS
4. Sets up automatic certificate renewal

> [!INFO]
> Your domain's DNS must point to your server before running this command.

## Next Steps

With your application deployed and secured with HTTPS, you may want to set up automation for scheduled tasks and long-running processes. See [Crons and Supervisors](/docs/crons-and-supervisors) to learn how to configure Laravel schedulers, queue workers, and other background processes.
