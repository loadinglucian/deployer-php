# Command Reference: AWS

<!-- toc -->

- [aws:provision](#aws-provision)
- [aws:key:list](#aws-key-list)
- [aws:key:add](#aws-key-add)
- [aws:key:delete](#aws-key-delete)
- [aws:dns:list](#aws-dns-list)
- [aws:dns:set](#aws-dns-set)
- [aws:dns:delete](#aws-dns-delete)

<!-- /toc -->

Use these commands to provision EC2 resources, manage key pairs, and maintain Route53 DNS records.

<a name="aws-provision"></a>

## aws:provision

- **What it does**: Provisions a new AWS EC2 instance and adds it to local inventory.
- **When to use it**: Creating new environment capacity directly from DeployerPHP.
- **Prerequisites**: Valid AWS credentials, IAM permissions, and a local SSH public key strategy.
- **Effects on server/inventory/resources**: Creates EC2 infrastructure and writes new server inventory entry.
- **Related commands**: `server:install`, `aws:key:add`, `server:delete`.
- **Failure/guardrail behavior**: Applies rollback cleanup when provisioning fails after partial resource creation.

<a name="aws-key-list"></a>

## aws:key:list

- **What it does**: Lists EC2 key pairs available in the AWS account/region context.
- **When to use it**: Auditing available key pairs before provisioning.
- **Prerequisites**: Valid AWS credentials with key pair read permissions.
- **Effects on server/inventory/resources**: Read-only operation.
- **Related commands**: `aws:key:add`, `aws:key:delete`, `aws:provision`.
- **Failure/guardrail behavior**: Returns provider errors for auth, region, or permissions issues.

<a name="aws-key-add"></a>

## aws:key:add

- **What it does**: Imports a local SSH public key into AWS key pairs.
- **When to use it**: Preparing key material for instance provisioning.
- **Prerequisites**: Local public key file and AWS permissions for key import.
- **Effects on server/inventory/resources**: Creates provider-side key pair resource.
- **Related commands**: `aws:key:list`, `aws:key:delete`, `aws:provision`.
- **Failure/guardrail behavior**: Fails on missing/invalid key files or provider API rejection.

<a name="aws-key-delete"></a>

## aws:key:delete

- **What it does**: Deletes a key pair from AWS.
- **When to use it**: Rotating credentials or cleaning up unused keys.
- **Prerequisites**: Existing key pair and proper AWS permissions.
- **Effects on server/inventory/resources**: Removes provider-side key pair.
- **Related commands**: `aws:key:list`, `aws:key:add`.
- **Failure/guardrail behavior**: Uses confirmation flow and reports provider deletion errors directly.

<a name="aws-dns-list"></a>

## aws:dns:list

- **What it does**: Lists Route53 DNS records for a selected hosted zone.
- **When to use it**: DNS auditing and verification.
- **Prerequisites**: AWS credentials with Route53 read permissions.
- **Effects on server/inventory/resources**: Read-only operation.
- **Related commands**: `aws:dns:set`, `aws:dns:delete`, `site:dns:check`.
- **Failure/guardrail behavior**: Returns clear zone resolution and provider API errors.

<a name="aws-dns-set"></a>

## aws:dns:set

- **What it does**: Creates or updates a DNS record in Route53.
- **When to use it**: Pointing domains/subdomains to provisioned servers.
- **Prerequisites**: Hosted zone access and valid record target values.
- **Effects on server/inventory/resources**: Upserts provider DNS records.
- **Related commands**: `aws:dns:list`, `aws:dns:delete`, `site:https`.
- **Failure/guardrail behavior**: Stops on invalid record operations and surfaces provider validation failures.

<a name="aws-dns-delete"></a>

## aws:dns:delete

- **What it does**: Deletes a DNS record from Route53.
- **When to use it**: Cleaning up obsolete DNS entries.
- **Prerequisites**: Hosted zone access and resolvable target record.
- **Effects on server/inventory/resources**: Removes provider DNS records.
- **Related commands**: `aws:dns:list`, `aws:dns:set`.
- **Failure/guardrail behavior**: Requires explicit target selection and returns provider deletion errors directly.
