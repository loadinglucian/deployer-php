# Pro

- [Introduction](#introduction)
- [Server Access](#server-access)
    - [SSH Access](#server-ssh)
    - [Viewing Logs](#server-logs)
- [Site Access](#site-access)
    - [SSH Access](#site-ssh)
- [AWS EC2](#aws-ec2)
    - [Configuration](#aws-configuration)
    - [Managing SSH Keys](#aws-ssh-keys)
    - [Provisioning Servers](#aws-provisioning)
- [DigitalOcean](#digitalocean)
    - [Configuration](#do-configuration)
    - [Managing SSH Keys](#do-ssh-keys)
    - [Provisioning Droplets](#do-provisioning)

<a name="introduction"></a>

## Introduction

DeployerPHP's Pro features integrate with cloud providers to provision servers directly from the command line. Instead of manually creating servers through web dashboards, you can provision, configure, and destroy cloud resources with simple commands.

Currently supported providers:

- **AWS EC2** - Amazon's Elastic Compute Cloud
- **DigitalOcean** - Simple cloud hosting with Droplets

> [!NOTE]
> Pro features require API credentials from your cloud provider. These credentials are stored locally and never transmitted to third parties.

<a name="server-access"></a>

## Server Access

Pro commands for accessing and monitoring your servers.

<a name="server-ssh"></a>

### SSH Access

The `pro:server:ssh` command opens an interactive SSH session to a server:

```bash
deployer pro:server:ssh
```

You'll be prompted to select a server from your inventory, then dropped into a terminal session on the remote server. Use `exit` to return to your local machine.

| Option     | Description |
| ---------- | ----------- |
| `--server` | Server name |

For automation:

```bash
deployer pro:server:ssh --server=production
```

> [!NOTE]
> This command is also available as `server:ssh` for convenience.

<a name="server-logs"></a>

### Viewing Logs

The `pro:server:logs` command provides a unified interface for viewing all logs on a server:

```bash
deployer pro:server:logs --server=production
```

When run interactively, you'll see a multiselect prompt with all available log sources. You can select multiple sources at once to view logs from different services in a single command.

Available log sources include:

- **System logs** - General system logs via journalctl
- **Service logs** - Nginx, SSH, PHP-FPM (per version), MySQL, MariaDB, PostgreSQL, Redis, Valkey, Memcached
- **Site access logs** - Per-site Nginx access logs
- **Cron script logs** - Output from individual cron scripts
- **Supervisor program logs** - Output from supervisor programs

| Option          | Description                            | Default    |
| --------------- | -------------------------------------- | ---------- |
| `--server`      | Server name                            | (prompted) |
| `--site`        | Filter logs to a specific site         | (none)     |
| `--service, -s` | Service(s) to view (comma-separated)   | (prompted) |
| `--lines, -n`   | Number of lines to retrieve per source | 50         |

For automation:

```bash
deployer pro:server:logs \
    --server=production \
    --service=nginx,php8.3-fpm,mysql \
    --lines=100
```

> [!NOTE]
> This command is also available as `server:logs` for convenience. For detailed documentation, see the [Viewing Logs](/docs/servers#viewing-logs) section in server management.

<a name="site-access"></a>

## Site Access

Pro commands for accessing your sites.

<a name="site-ssh"></a>

### SSH Access

The `pro:site:ssh` command opens an SSH session directly in a site's directory:

```bash
deployer pro:site:ssh
```

You'll be prompted to select a site from your inventory. The session opens in the site's current release directory (`/home/deployer/sites/{domain}/current/`) as the `deployer` user.

| Option     | Description |
| ---------- | ----------- |
| `--domain` | Site domain |

For automation:

```bash
deployer pro:site:ssh --domain=example.com
```

> [!NOTE]
> This command is also available as `site:ssh` for convenience.

<a name="aws-ec2"></a>

## AWS EC2

DeployerPHP can provision EC2 instances and manage SSH keys in your AWS account.

<a name="aws-configuration"></a>

### Configuration

Set your AWS credentials as environment variables:

```bash
export AWS_ACCESS_KEY_ID="your-access-key"
export AWS_SECRET_ACCESS_KEY="your-secret-key"
export AWS_DEFAULT_REGION="us-east-1"
```

Or create a `.env` file in your project:

```env
AWS_ACCESS_KEY_ID=your-access-key
AWS_SECRET_ACCESS_KEY=your-secret-key
AWS_DEFAULT_REGION=us-east-1
```

Required IAM permissions:

- `ec2:RunInstances`
- `ec2:DescribeInstances`
- `ec2:TerminateInstances`
- `ec2:DescribeInstanceTypes`
- `ec2:CreateKeyPair`
- `ec2:DeleteKeyPair`
- `ec2:DescribeKeyPairs`
- `ec2:DescribeImages`
- `ec2:DescribeVpcs`
- `ec2:DescribeSubnets`
- `ec2:CreateSecurityGroup`
- `ec2:AuthorizeSecurityGroupIngress`
- `ec2:DescribeSecurityGroups`
- `ec2:AllocateAddress`
- `ec2:ReleaseAddress`
- `ec2:AssociateAddress`
- `ec2:DisassociateAddress`
- `sts:GetCallerIdentity`

<a name="aws-ssh-keys"></a>

### Managing SSH Keys

Before provisioning, upload your SSH public key to AWS:

```bash
# List existing keys
deployer pro:aws:key:list

# Add a new key
deployer pro:aws:key:add

# Delete a key
deployer pro:aws:key:delete
```

#### Listing Keys

The `pro:aws:key:list` command displays all EC2 key pairs in your configured AWS region. It shows each key's name along with a truncated fingerprint for identification.

This command has no options beyond the standard `--env` and `--inventory` flags.

#### Adding Keys

When adding a key, you'll be prompted for:

- **Public key path** - Path to your `.pub` file (leave empty to auto-detect `~/.ssh/id_ed25519.pub` or `~/.ssh/id_rsa.pub`)
- **Key pair name** - Identifier in AWS (default: `deployer-key`)

| Option              | Description          | Default        |
| ------------------- | -------------------- | -------------- |
| `--public-key-path` | SSH public key path  | Auto-detected  |
| `--name`            | Key pair name in AWS | `deployer-key` |

Example:

```bash
deployer pro:aws:key:add \
    --public-key-path=~/.ssh/id_ed25519.pub \
    --name=deployer-key
```

#### Deleting Keys

When deleting a key, you'll be prompted for:

- **Key** - The AWS key pair name to delete
- **Type-to-confirm** - Type the key name to confirm deletion
- **Yes/No confirmation** - Final confirmation before deletion

| Option           | Description                         |
| ---------------- | ----------------------------------- |
| `--key`          | AWS key pair name                   |
| `--force` / `-f` | Skip typing the key name to confirm |
| `--yes` / `-y`   | Skip Yes/No confirmation prompt     |

Example:

```bash
deployer pro:aws:key:delete \
    --key=deployer-key \
    --force \
    --yes
```

<a name="aws-provisioning"></a>

### Provisioning Servers

The `pro:aws:provision` command creates a new EC2 instance:

```bash
deployer pro:aws:provision
```

You'll be prompted for server details, instance configuration, and network settings. The command supports two approaches for instance type selection:

**Direct instance type:**

```bash
deployer pro:aws:provision --instance-type=t3.large
```

**Two-step selection (family + size):**

```bash
deployer pro:aws:provision --instance-family=t3 --instance-size=large
```

#### Options

| Option               | Description                                             |
| -------------------- | ------------------------------------------------------- |
| `--name`             | Server name for your inventory                          |
| `--instance-type`    | Full instance type (e.g., t3.large) - skips family/size |
| `--instance-family`  | Instance family (e.g., t3, m6i, c7g)                    |
| `--instance-size`    | Instance size (e.g., micro, large, xlarge)              |
| `--ami`              | AMI ID for the OS image                                 |
| `--key-pair`         | AWS key pair name for SSH access                        |
| `--private-key-path` | Path to your SSH private key                            |
| `--vpc`              | VPC ID for network isolation                            |
| `--subnet`           | Subnet ID (determines availability zone)                |
| `--disk-size`        | Root disk size in GB (default: 8)                       |
| `--monitoring`       | Enable detailed CloudWatch monitoring (extra cost)      |
| `--no-monitoring`    | Disable detailed monitoring                             |

#### Example

```bash
deployer pro:aws:provision \
    --name=production \
    --instance-type=t3.small \
    --ami=ami-0123456789abcdef0 \
    --key-pair=deployer-key \
    --private-key-path=~/.ssh/id_rsa \
    --vpc=vpc-12345678 \
    --subnet=subnet-12345678 \
    --disk-size=20 \
    --monitoring
```

DeployerPHP will:

1. Verify your instance type is available in the selected region
2. Create or reuse a "deployer" security group with SSH (22), HTTP (80), and HTTPS (443) rules
3. Launch an EC2 instance with the selected OS and configuration
4. Wait for the instance to reach the running state
5. Allocate a new Elastic IP address and associate it with the instance
6. Verify SSH connectivity to the new server
7. Add the server to your local inventory

If any step fails after the instance is created, DeployerPHP automatically rolls back by releasing the Elastic IP and terminating the instance.

After provisioning, install the server:

```bash
deployer server:install --server=production
```

> [!NOTE]
> When you delete a server provisioned through AWS, DeployerPHP also terminates the EC2 instance and releases the Elastic IP.

<a name="digitalocean"></a>

## DigitalOcean

DeployerPHP can provision Droplets and manage SSH keys in your DigitalOcean account.

<a name="do-configuration"></a>

### Configuration

Set your DigitalOcean API token as an environment variable:

```bash
export DIGITALOCEAN_TOKEN="your-api-token"
```

Or in a `.env` file:

```env
DIGITALOCEAN_TOKEN=your-api-token
```

Generate an API token at [https://cloud.digitalocean.com/account/api/tokens](https://cloud.digitalocean.com/account/api/tokens) with read and write access.

<a name="do-ssh-keys"></a>

### Managing SSH Keys

```bash
# List existing keys
deployer pro:do:key:list

# Add a new key
deployer pro:do:key:add

# Delete a key
deployer pro:do:key:delete
```

#### Adding Keys

When adding a key, you'll be prompted for:

- **Public key path** - Path to your `.pub` file (leave empty to auto-detect `~/.ssh/id_ed25519.pub` or `~/.ssh/id_rsa.pub`)
- **Key name** - Identifier in DigitalOcean (default: `deployer-key`)

| Option              | Description                      | Default        |
| ------------------- | -------------------------------- | -------------- |
| `--public-key-path` | SSH public key path              | Auto-detected  |
| `--name`            | Key name in DigitalOcean account | `deployer-key` |

Example:

```bash
deployer pro:do:key:add \
    --public-key-path=~/.ssh/id_ed25519.pub \
    --name=deployer-key
```

#### Deleting Keys

When deleting a key, you'll be prompted for:

- **Key** - The DigitalOcean SSH key ID to delete
- **Type-to-confirm** - Type the key ID to confirm deletion
- **Yes/No confirmation** - Final confirmation before deletion

| Option           | Description                       |
| ---------------- | --------------------------------- |
| `--key`          | DigitalOcean public SSH key ID    |
| `--force` / `-f` | Skip typing the key ID to confirm |
| `--yes` / `-y`   | Skip Yes/No confirmation prompt   |

Example:

```bash
deployer pro:do:key:delete \
    --key=12345678 \
    --force \
    --yes
```

<a name="do-provisioning"></a>

### Provisioning Droplets

The `pro:do:provision` command creates a new Droplet:

```bash
deployer pro:do:provision
```

You'll be prompted for server details, droplet configuration, and optional features.

#### Options

| Option               | Description                            |
| -------------------- | -------------------------------------- |
| `--name`             | Server name for your inventory         |
| `--region`           | DigitalOcean region (e.g., nyc3, sfo3) |
| `--size`             | Droplet size (e.g., s-1vcpu-1gb)       |
| `--image`            | OS image (e.g., ubuntu-24-04-x64)      |
| `--ssh-key-id`       | SSH key ID in DigitalOcean             |
| `--private-key-path` | Path to your SSH private key           |
| `--vpc-uuid`         | VPC UUID for network isolation         |
| `--backups`          | Enable automatic backups (extra cost)  |
| `--no-backups`       | Disable automatic backups              |
| `--monitoring`       | Enable monitoring metrics (free)       |
| `--no-monitoring`    | Disable monitoring                     |
| `--ipv6`             | Enable IPv6 address (free)             |
| `--no-ipv6`          | Disable IPv6                           |

#### Example

```bash
deployer pro:do:provision \
    --name=production \
    --region=nyc3 \
    --size=s-1vcpu-2gb \
    --image=ubuntu-24-04-x64 \
    --ssh-key-id=12345678 \
    --private-key-path=~/.ssh/id_rsa \
    --monitoring \
    --ipv6
```

DeployerPHP will:

1. Create a Droplet with the selected OS
2. Wait for the Droplet to become active
3. Add the server to your local inventory

After provisioning:

```bash
deployer server:install --server=production
```

> [!NOTE]
> When you delete a server provisioned through DigitalOcean, DeployerPHP also destroys the Droplet.

### Available Regions

Common DigitalOcean regions:

| Slug           | Location      |
| -------------- | ------------- |
| `nyc1`, `nyc3` | New York      |
| `sfo3`         | San Francisco |
| `ams3`         | Amsterdam     |
| `sgp1`         | Singapore     |
| `lon1`         | London        |
| `fra1`         | Frankfurt     |
| `tor1`         | Toronto       |
| `blr1`         | Bangalore     |

### Available Sizes

Common Droplet sizes:

| Slug                 | Specs               | Monthly |
| -------------------- | ------------------- | ------- |
| `s-1vcpu-512mb-10gb` | 1 vCPU, 512MB, 10GB | $4      |
| `s-1vcpu-1gb`        | 1 vCPU, 1GB, 25GB   | $6      |
| `s-1vcpu-2gb`        | 1 vCPU, 2GB, 50GB   | $12     |
| `s-2vcpu-4gb`        | 2 vCPU, 4GB, 80GB   | $24     |
| `s-4vcpu-8gb`        | 4 vCPU, 8GB, 160GB  | $48     |

Use `deployer pro:do:provision` interactively to see all available options.
