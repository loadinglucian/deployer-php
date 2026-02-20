# Zero to Deploy

<!-- toc -->

- [Step 1: Your Server](#step-1-your-server)
- [Step 2: Your Site](#step-2-your-site)
- [Step 3: Deploy](#step-3-deploy)
- [Next Steps](#next-steps)

<!-- /toc -->

This guide is going to walk you through deploying your first application with DeployerPHP. By the time we're done, DeployerPHP will have:

- Fully configured a server runtime environment with Nginx, PHP, Bun, and a dedicated deployment user with its own deploy key
- Set up additional PHP versions and your preferred database or cache services (MariaDB, PostgreSQL, Redis, or Memcached)
- Created a deploy-ready site structure with releases, shared resources, and zero downtime deployment support
- Deployed your application from Git using a customizable deployment script for build, migration, cron, and worker workflows
- Enabled Let's Encrypt HTTPS and automatic renewal with guided DNS setup and verification for your domain

All you have to do is run a few simple commands and respond to a couple of interactive prompts. DeployerPHP will take care of all the hard stuff.

## Step 1: Your Server

Before we can deploy anything, we'll need a server to deploy to. You can use any physical server, VPS, or cloud instance as long as you can SSH into it and it runs Ubuntu LTS >= 24.04 (no interim releases like 25.04).

### Adding a Server

Run the `server:add` command to add a new server to your inventory:

```shell
deployer server:add
```

The command will ask for your server details, including the host/IP, SSH port, username, key, and a name for your new server. It will try connecting to the server and then add it to the inventory:

```EXAMPLE nocopy
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

For more information, see [Server Reference](reference-server.md).

### Cloud Providers

Alternatively, you can provision a cloud instance and add it to the inventory automatically using one of the dedicated cloud provider commands:

| Command         | Description                                          |
| --------------- | ---------------------------------------------------- |
| `aws:provision` | Provision AWS EC2 instances and add to inventory     |
| `do:provision`  | Provision DigitalOcean droplets and add to inventory |

For more information, see [AWS Reference](reference-aws.md) and [DigitalOcean Reference](reference-digitalocean.md).

### Installing the Server

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
- **Dedicated user** - Dedicated `deployer` user with its own SSH key pair

```EXAMPLE nocopy
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

For more information, see [Nginx Reference](reference-nginx.md) and [PHP-FPM Reference](reference-php.md).

### Installing Databases

You can install your preferred database or cache services by running one of the dedicated installation commands:

| Command              | Description                        |
| -------------------- | ---------------------------------- |
| `mariadb:install`    | Install MariaDB database server    |
| `postgresql:install` | Install PostgreSQL database server |
| `redis:install`      | Install Redis key-value store      |
| `memcached:install`  | Install Memcached caching server   |

For more information, see [MariaDB Reference](reference-mariadb.md), [PostgreSQL Reference](reference-postgresql.md), [Redis Reference](reference-redis.md), and [Memcached Reference](reference-memcached.md).

## Step 2: Your Site

At this stage, your server runtime environment should be fully installed and prepared for your application. Next, it's time to create and deploy your site.

### Creating a Site

Run the `site:create` command to create a new site:

```shell
deployer site:create
```

This creates an Nginx configuration as well as a deploy-ready directory structure with releases, shared resources, and a `current` symlink for zero-downtime deployments:

```EXAMPLE nocopy
/home/deployer/sites/example.com/
├── current -> releases/...   # Symlink to active release
├── releases/                 # Timestamped deployments
│   └── .../
├── repo/                     # Git repository clone
└── shared/                   # Persistent files (.env, storage, etc.)
```

### Pointing Your DNS

Point your DNS to the server through your DNS provider:

- **A record**: Point your domain to your server's IP address
- **AAAA records** (optional): Point your domain to your server's IPv6 addresses
- **CNAME record** (optional): Point the `www` subdomain to your main domain

DNS propagation typically takes a while, so the sooner you can get it out of the way, the better. Run the `deployer site:dns:check` command to check your DNS resolution:

```shell
deployer site:dns:check
```

If you use any of the supported DNS providers, you can configure your DNS using one of the dedicated provider commands:

| Command       | Description                                |
| ------------- | ------------------------------------------ |
| `aws:dns:set` | Create or update an AWS Route53 record     |
| `cf:dns:set`  | Create or update a Cloudflare DNS record   |
| `do:dns:set`  | Create or update a DigitalOcean DNS record |

For more information, see [AWS Reference](reference-aws.md), [Cloudflare Reference](reference-cloudflare.md), and [DigitalOcean Reference](reference-digitalocean.md).

### Enable HTTPS

Run the `site:https` command to install an SSL certificate:

```shell
deployer site:https
```

This installs Certbot, obtains a Let's Encrypt certificate, configures Nginx for HTTPS, and sets up automatic certificate renewal.

> [!INFO]
> Your domain's DNS must point to your server before running this command.

### Shared Files

Shared files and directories persist across releases. Common examples include `.env` files, user-uploaded content, and SQLite databases. The deploy script links specific shared items into each release, giving you fine-grained control over what is shared and how.

Use the `site:shared:push` command to upload a file to a site's shared directory:

```shell
deployer site:shared:push
```

The command will prompt you for the server, site, local file path, and remote file path within the shared directory. Use `site:shared:pull` to download a shared file to your local machine.

> [!INFO]
> The `site:shared:*` commands only support single files. You can create any shared directory structures your application needs in the deploy script. For more information, see the next section.

### Scaffolding Scripts

Run the `scaffold:scripts` command in your project directory to scaffold a few sample scripts:

```shell
deployer scaffold:scripts
```

This creates `deploy.sh`, `cron.sh`, and `supervisor.sh` in your project's `.deployer/scripts` directory:

- The `deploy.sh` script handles your project's deployment workflow by installing dependencies, building assets, linking shared resources, running migrations, and optimizing caches.
- The `cron.sh` and `supervisor.sh` scripts serve as starting points for scheduled tasks and long-running workers.

Each script has access to several environment variables (see the scaffolded scripts for a complete reference) and runs in the release directory as the dedicated `deployer` user. Adding `set -e` at the top ensures that the deployment stops if any command fails, preventing a broken release from going live.

> [!INFO]
> The deploy script is the ideal place to create shared directories your application needs. For example, if your application stores user uploads, create the directory with `mkdir -p "$DEPLOYER_SHARED_PATH/uploads"` and symlink it into the release.

## Step 3: Deploy

Run the `site:deploy` command to deploy your application from a Git repository:

```shell
deployer site:deploy
```

The command will ask for your repository details, including repository URL and the branch to deploy. It will try connecting to the repository and then add it to the inventory.

The deployment process will begin immediately afterward. DeployerPHP uses a release-based deployment model that enables zero-downtime deployments. Instead of updating files in place, each deployment creates a new timestamped release directory. Once the release is fully prepared, the `current` symlink atomically switches to point to the new release. This atomic symlink swap means your application is never in a partially-updated state during deployment.

### The Deployment

Understanding the deployment lifecycle helps you write effective deployment scripts and troubleshoot issues. Here's what happens when you run `site:deploy`:

1. **Repository Setup** - On the first deployment, DeployerPHP clones your repository into the `repo/` directory. On subsequent deployments, it only fetches the latest changes from the remote repository.

2. **Release Creation** - A new timestamped directory is created in `releases/` (e.g., `releases/20240115_143052`). Your code is exported from the repository into this directory using `git archive`, ensuring a clean copy without Git metadata.

3. **Deploy Script** - If your project has a `.deployer/scripts/deploy.sh` script, it runs now. This script handles your project's deployment workflow: installing dependencies, building assets, linking shared resources, running migrations, and optimizing caches. The release is isolated, so failures won't affect your live site.

4. **Activation** - The `current` symlink atomically switches to point to the new release. This is the moment your new code goes live. The atomic nature of symlink operations means there's no "in-between" state.

5. **PHP-FPM Reload** - DeployerPHP reloads PHP-FPM to clear the opcode cache, ensuring PHP serves your new code immediately.

6. **Cleanup** - Old releases beyond the keep count are removed to free disk space.

### Release History

Each deployment creates a new release directory with a timestamp in the format `YYYYMMDD_HHMMSS`. The `current` symlink always points to the active release.

By default, DeployerPHP keeps the 5 most recent releases. You can customize this when running the deploy command.

You can manually switch back to a previous release by updating the `current` symlink to point to an older release directory and reloading PHP-FPM. That said, DeployerPHP espouses a forward-only deployment philosophy.

> [!IMPORTANT]
> DeployerPHP uses a forward-only deployment philosophy:
>
> - Rollbacks mask problems rather than fixing them. The underlying issue remains.
> - Forward-only fixes create an auditable history of what changed and why.
> - Modern CI/CD makes deploying a fix just as fast as rolling back.

## Next Steps

With your server installed and your application deployed and secured with HTTPS, you may want to set up some scheduled tasks and long-running processes. For more information, see [Cron Reference](reference-cron.md) and [Supervisor Reference](reference-supervisor.md).
