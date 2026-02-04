# Cloud Providers

<!-- toc -->

- [AWS](#aws)
    - [Configuration](#configuration)
    - [IAM Permissions](#iam-permissions)
    - [Managing SSH Keys](#managing-ssh-keys)
    - [Provisioning Servers](#provisioning-servers)
    - [Managing DNS Records](#managing-dns-records)
- [Cloudflare](#cloudflare)
    - [Configuration](#configuration-1)
    - [Managing DNS Records](#managing-dns-records-1)
- [DigitalOcean](#digitalocean)
    - [Configuration](#configuration-2)
    - [Managing SSH Keys](#managing-ssh-keys-1)
    - [Provisioning Droplets](#provisioning-droplets)
    - [Managing DNS Records](#managing-dns-records-2)

<!-- /toc -->

Managing cloud infrastructure from the command line keeps your deployment workflow consistent and scriptable. DeployerPHP integrates with AWS, Cloudflare, and DigitalOcean to provision servers, manage SSH keys, and configure DNS records without leaving your terminal.

## AWS

DeployerPHP can provision EC2 instances, manage SSH keys, and configure Route53 DNS records in your AWS account.

### Configuration

Set your AWS credentials as environment variables:

```shell
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

### IAM Permissions

Your IAM user needs permissions for EC2 and Route53 operations. Here's the complete list:

**EC2 Permissions** (for server provisioning and SSH key management):

- `ec2:RunInstances` - Launch new instances
- `ec2:DescribeInstances` - Check instance status
- `ec2:TerminateInstances` - Clean up instances
- `ec2:ImportKeyPair` - Upload SSH public keys
- `ec2:DescribeKeyPairs` - List available keys
- `ec2:DeleteKeyPair` - Remove keys
- `ec2:AllocateAddress` - Reserve Elastic IPs
- `ec2:AssociateAddress` - Attach IPs to instances
- `ec2:ReleaseAddress` - Release Elastic IPs
- `ec2:DescribeAddresses` - Find associated IPs
- `ec2:DescribeSecurityGroups` - Find security groups
- `ec2:CreateSecurityGroup` - Create the "deployer" group
- `ec2:AuthorizeSecurityGroupIngress` - Configure inbound rules
- `ec2:DeleteSecurityGroup` - Clean up on failure
- `ec2:DescribeVpcs` - List available VPCs
- `ec2:DescribeSubnets` - List subnets
- `ec2:DescribeRegions` - List AWS regions
- `ec2:DescribeInstanceTypes` - Query instance availability
- `ec2:DescribeImages` - Find OS images (Ubuntu, Debian)

**Route53 Permissions** (for DNS management):

- `route53:ListHostedZones` - List your DNS zones
- `route53:GetHostedZone` - Get zone details
- `route53:ListResourceRecordSets` - List DNS records
- `route53:ChangeResourceRecordSets` - Create, update, delete records

**STS Permissions** (for credential verification):

- `sts:GetCallerIdentity` - Verify AWS credentials before API calls

You can create an IAM policy with these permissions in the [AWS IAM Console](https://console.aws.amazon.com/iam/). For convenience, here's a ready-to-use policy:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "ec2:RunInstances",
                "ec2:DescribeInstances",
                "ec2:TerminateInstances",
                "ec2:ImportKeyPair",
                "ec2:DescribeKeyPairs",
                "ec2:DeleteKeyPair",
                "ec2:AllocateAddress",
                "ec2:AssociateAddress",
                "ec2:ReleaseAddress",
                "ec2:DescribeAddresses",
                "ec2:DescribeSecurityGroups",
                "ec2:CreateSecurityGroup",
                "ec2:AuthorizeSecurityGroupIngress",
                "ec2:DeleteSecurityGroup",
                "ec2:DescribeVpcs",
                "ec2:DescribeSubnets",
                "ec2:DescribeRegions",
                "ec2:DescribeInstanceTypes",
                "ec2:DescribeImages"
            ],
            "Resource": "*"
        },
        {
            "Effect": "Allow",
            "Action": ["route53:ListHostedZones", "route53:GetHostedZone", "route53:ListResourceRecordSets", "route53:ChangeResourceRecordSets"],
            "Resource": "*"
        },
        {
            "Effect": "Allow",
            "Action": "sts:GetCallerIdentity",
            "Resource": "*"
        }
    ]
}
```

> [!TIP]
> If you only need DNS management (no server provisioning), you can create a policy with just the Route53 permissions.

### Managing SSH Keys

Before provisioning, upload your SSH public key to AWS:

```shell
# List existing keys
deployer aws:key:list

# Add a new key
deployer aws:key:add

# Delete a key
deployer aws:key:delete
```

When adding a key, you'll be prompted for the path to your public key file (auto-detects `~/.ssh/id_ed25519.pub` or `~/.ssh/id_rsa.pub`) and a name for the key pair in AWS.

### Provisioning Servers

The `aws:provision` command creates a new EC2 instance:

```shell
deployer aws:provision
```

You'll be prompted for server details, instance configuration, and network settings. DeployerPHP will:

1. Verify your instance type is available in the selected region
2. Create or reuse a "deployer" security group with SSH (22), HTTP (80), and HTTPS (443) rules
3. Launch an EC2 instance with the selected OS and configuration
4. Wait for the instance to reach the running state
5. Allocate a new Elastic IP address and associate it with the instance
6. Verify SSH connectivity to the new server
7. Add the server to your local inventory

If any step fails after the instance is created, DeployerPHP automatically rolls back by releasing the Elastic IP and terminating the instance.

After provisioning, run `deployer server:install` to set up the server.

> [!NOTE]
> When you delete a server provisioned through AWS, DeployerPHP also terminates the EC2 instance and releases the Elastic IP.

### Managing DNS Records

DeployerPHP can manage DNS records in your Route53 hosted zones:

```shell
# List DNS records
deployer aws:dns:list

# Create or update a record
deployer aws:dns:set

# Delete a record
deployer aws:dns:delete
```

The `aws:dns:set` command creates a new DNS record or updates an existing one (upsert). When prompted for a record name, use `@` for the root domain.

## Cloudflare

DeployerPHP can manage DNS records in your Cloudflare zones.

### Configuration

Set your Cloudflare API token as an environment variable:

```shell
export CLOUDFLARE_API_TOKEN="your-api-token"
```

Or in a `.env` file:

```env
CLOUDFLARE_API_TOKEN=your-api-token
```

Generate an API token at [dash.cloudflare.com/profile/api-tokens](https://dash.cloudflare.com/profile/api-tokens). Your token needs the **Zone:DNS:Edit** permission for the zones you want to manage.

### Managing DNS Records

```shell
# List DNS records
deployer cf:dns:list

# Create or update a record
deployer cf:dns:set

# Delete a record
deployer cf:dns:delete
```

The `cf:dns:set` command creates a new DNS record or updates an existing one. Cloudflare supports proxying traffic through their CDN and DDoS protection network. When proxy is enabled, Cloudflare hides your origin IP address and routes traffic through their global network.

> [!NOTE]
> Cloudflare commands also support the full `cloudflare:` prefix (e.g., `cloudflare:dns:list`).

## DigitalOcean

DeployerPHP can provision Droplets, manage SSH keys, and configure DNS records in your DigitalOcean account.

### Configuration

Set your DigitalOcean API token as an environment variable:

```shell
export DIGITALOCEAN_TOKEN="your-api-token"
```

Or in a `.env` file:

```env
DIGITALOCEAN_TOKEN=your-api-token
```

Generate an API token at [cloud.digitalocean.com/account/api/tokens](https://cloud.digitalocean.com/account/api/tokens) with read and write access.

### Managing SSH Keys

```shell
# List existing keys
deployer do:key:list

# Add a new key
deployer do:key:add

# Delete a key
deployer do:key:delete
```

When adding a key, you'll be prompted for the path to your public key file (auto-detects `~/.ssh/id_ed25519.pub` or `~/.ssh/id_rsa.pub`) and a name for the key in DigitalOcean.

### Provisioning Droplets

The `do:provision` command creates a new Droplet:

```shell
deployer do:provision
```

You'll be prompted for server details, droplet configuration, and optional features like backups, monitoring, and IPv6. DeployerPHP will:

1. Create a Droplet with the selected OS
2. Wait for the Droplet to become active
3. Add the server to your local inventory

After provisioning, run `deployer server:install` to set up the server.

> [!NOTE]
> When you delete a server provisioned through DigitalOcean, DeployerPHP also destroys the Droplet.

### Managing DNS Records

DeployerPHP can manage DNS records for domains in your DigitalOcean account:

```shell
# List DNS records
deployer do:dns:list

# Create or update a record
deployer do:dns:set

# Delete a record
deployer do:dns:delete
```

Your domain must be added to DigitalOcean's DNS management before you can create records. The `do:dns:set` command creates a new DNS record or updates an existing one. When prompted for a record name, use `@` for the root domain.

> [!NOTE]
> DigitalOcean commands also support the full `digitalocean:` prefix (e.g., `digitalocean:dns:list`).
