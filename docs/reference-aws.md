# AWS Reference

<!-- toc -->

- [Configuration](#configuration)
- [IAM Permissions](#iam-permissions)
- [At a Glance](#at-a-glance)
- [SSH Key Management](#ssh-key-management)
- [Provisioning](#provisioning)
- [DNS Management](#dns-management)

<!-- /toc -->

Use `aws:*` commands to provision EC2 servers, manage SSH keys, and manage Route53 DNS records.

<a name="configuration"></a>

## Configuration

Set AWS credentials in your environment:

```dotenv
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_REGION=us-east-1
```

<a name="iam-permissions"></a>

## IAM Permissions

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

> [!NOTE]
> If you only need DNS management, you can scope IAM permissions to Route53 and STS only.

<a name="at-a-glance"></a>

## At a Glance

| Command          | Use it when you need to...                        |
| ---------------- | ------------------------------------------------- |
| `aws:provision`  | create an EC2 server and register it in inventory |
| `aws:key:list`   | review available AWS key pairs                    |
| `aws:key:add`    | import a local public key into AWS                |
| `aws:key:delete` | remove an AWS key pair                            |
| `aws:dns:list`   | list records in a Route53 hosted zone             |
| `aws:dns:set`    | create or update a Route53 DNS record             |
| `aws:dns:delete` | remove a Route53 DNS record                       |

<a name="ssh-key-management"></a>

## SSH Key Management

Use the `aws:key:*` commands to keep key inventory aligned with your access policy before provisioning. You can list existing key pairs, import a local public key, or remove a key pair you no longer need.

```shell
deployer aws:key:list
deployer aws:key:add
deployer aws:key:delete
```

<a name="provisioning"></a>

## Provisioning

`aws:provision` creates an EC2 instance, allocates an Elastic IP, configures a security group, and writes inventory entries so you can continue with `server:install` and site workflows immediately.

A shared "deployer" security group is created once per VPC and reused across provisions, so subsequent servers in the same VPC share the same firewall baseline.

If provisioning fails after the instance is created, DeployerPHP automatically rolls back the instance and Elastic IP so you don't accumulate orphaned resources.

```shell
deployer aws:provision
```

After provisioning, run the `server:install` command to prepare runtime services.

<a name="dns-management"></a>

## DNS Management

Use `aws:dns:list` to inspect current records in a Route53 hosted zone, then use `aws:dns:set` and `aws:dns:delete` for deliberate changes.

Note that `aws:dns:delete` cannot remove Route53 alias records. You'll need to manage alias records through the AWS Console.

```shell
deployer aws:dns:list
deployer aws:dns:set
deployer aws:dns:delete
```
