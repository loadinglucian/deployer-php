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
- [Operational Safety](#operational-safety)
- [Related References](#related-references)

<!-- /toc -->

Managing cloud infrastructure from the command line keeps your deployment workflow consistent. DeployerPHP supports AWS, Cloudflare, and DigitalOcean so provisioning and DNS operations stay close to your server and site lifecycle.

## AWS

DeployerPHP can provision EC2 instances, manage SSH keys, and manage Route53 DNS records.

### Configuration

Set AWS credentials in your environment:

```dotenv
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_REGION=us-east-1
```

### IAM Permissions

Your IAM user needs permissions for EC2 and Route53 operations.

**EC2 permissions** (server provisioning and SSH key management):

- `ec2:RunInstances`
- `ec2:DescribeInstances`
- `ec2:TerminateInstances`
- `ec2:ImportKeyPair`
- `ec2:DescribeKeyPairs`
- `ec2:DeleteKeyPair`
- `ec2:AllocateAddress`
- `ec2:AssociateAddress`
- `ec2:ReleaseAddress`
- `ec2:DescribeAddresses`
- `ec2:DescribeSecurityGroups`
- `ec2:CreateSecurityGroup`
- `ec2:AuthorizeSecurityGroupIngress`
- `ec2:DeleteSecurityGroup`
- `ec2:DescribeVpcs`
- `ec2:DescribeSubnets`
- `ec2:DescribeRegions`
- `ec2:DescribeInstanceTypes`
- `ec2:DescribeImages`

**Route53 permissions** (DNS management):

- `route53:ListHostedZones`
- `route53:GetHostedZone`
- `route53:ListResourceRecordSets`
- `route53:ChangeResourceRecordSets`

**STS permission** (credential verification):

- `sts:GetCallerIdentity`

You can create an IAM policy in the [AWS IAM Console](https://console.aws.amazon.com/iam/). This template matches the required action set:

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

> [!INFO]
> If you only need DNS management, you can scope IAM permissions to Route53 and STS only.

### Managing SSH Keys

```shell
# List keys
deployer aws:key:list

# Add a key
deployer aws:key:add

# Delete a key
deployer aws:key:delete
```

### Provisioning Servers

```shell
deployer aws:provision
```

After provisioning, run `deployer server:install` to prepare runtime services.

### Managing DNS Records

```shell
# List records
deployer aws:dns:list

# Create or update a record
deployer aws:dns:set

# Delete a record
deployer aws:dns:delete
```

## Cloudflare

DeployerPHP supports Cloudflare DNS operations.

### Configuration

Set your API token in the environment:

```dotenv
CLOUDFLARE_API_TOKEN=...
```

Create a token at [dash.cloudflare.com/profile/api-tokens](https://dash.cloudflare.com/profile/api-tokens) with `Zone:DNS:Edit` for the zones you manage.

### Managing DNS Records

```shell
# List records
deployer cf:dns:list

# Create or update a record
deployer cf:dns:set

# Delete a record
deployer cf:dns:delete
```

Cloudflare commands also support full alias prefixes:

- `cloudflare:dns:list`
- `cloudflare:dns:set`
- `cloudflare:dns:delete`

## DigitalOcean

DeployerPHP can provision Droplets, manage SSH keys, and manage DNS records in DigitalOcean.

### Configuration

Set your token in the environment:

```dotenv
DIGITALOCEAN_TOKEN=...
```

Create the token at [cloud.digitalocean.com/account/api/tokens](https://cloud.digitalocean.com/account/api/tokens) with read/write access.

### Managing SSH Keys

```shell
# List keys
deployer do:key:list

# Add a key
deployer do:key:add

# Delete a key
deployer do:key:delete
```

### Provisioning Droplets

```shell
deployer do:provision
```

After provisioning, run `deployer server:install` to prepare runtime services.

### Managing DNS Records

```shell
# List records
deployer do:dns:list

# Create or update a record
deployer do:dns:set

# Delete a record
deployer do:dns:delete
```

DigitalOcean commands also support full alias prefixes:

- `digitalocean:provision`
- `digitalocean:key:list`
- `digitalocean:key:add`
- `digitalocean:key:delete`
- `digitalocean:dns:list`
- `digitalocean:dns:set`
- `digitalocean:dns:delete`

## Operational Safety

Use this order when working with cloud resources:

1. Validate credentials and scope.
2. Confirm account/region or project context.
3. Apply infrastructure and DNS changes.
4. Verify outcome with `site:dns:check` and service/runtime checks.
5. Confirm cleanup for any destructive operations.

> [!IMPORTANT]
> Provisioning and deletion can cause cost and data-loss risk. Always confirm target account and resources before mutating cloud state.

## Related References

- [AWS Reference](reference-aws.md)
- [Cloudflare Reference](reference-cloudflare.md)
- [DigitalOcean Reference](reference-digitalocean.md)
- [Site Reference](reference-site.md)
