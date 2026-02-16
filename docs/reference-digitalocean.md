# Command Reference: DigitalOcean

<!-- toc -->

- [do:provision](#do-provision)
- [do:key:list](#do-key-list)
- [do:key:add](#do-key-add)
- [do:key:delete](#do-key-delete)
- [do:dns:list](#do-dns-list)
- [do:dns:set](#do-dns-set)
- [do:dns:delete](#do-dns-delete)
- [Alias Compatibility](#alias-compatibility)

<!-- /toc -->

Use these commands to provision droplets, manage account SSH keys, and maintain DNS records in DigitalOcean.

<a name="do-provision"></a>

## do:provision

- **What it does**: Provisions a new DigitalOcean droplet and adds it to local inventory.
- **When to use it**: Creating new server capacity directly from DeployerPHP.
- **Prerequisites**: Valid DigitalOcean API token and local SSH key strategy.
- **Effects on server/inventory/resources**: Creates droplet resources and writes server inventory entry.
- **Related commands**: `server:install`, `do:key:add`, `server:delete`.
- **Failure/guardrail behavior**: Performs cleanup actions for partial provisioning failures.

<a name="do-key-list"></a>

## do:key:list

- **What it does**: Lists SSH keys in your DigitalOcean account.
- **When to use it**: Auditing key readiness before droplet provisioning.
- **Prerequisites**: Valid DigitalOcean API token.
- **Effects on server/inventory/resources**: Read-only operation.
- **Related commands**: `do:key:add`, `do:key:delete`, `do:provision`.
- **Failure/guardrail behavior**: Returns token/auth/account errors directly.

<a name="do-key-add"></a>

## do:key:add

- **What it does**: Uploads a local SSH public key to DigitalOcean.
- **When to use it**: Preparing provisioning access.
- **Prerequisites**: Local public key file and valid API token.
- **Effects on server/inventory/resources**: Creates provider-side key resource.
- **Related commands**: `do:key:list`, `do:key:delete`, `do:provision`.
- **Failure/guardrail behavior**: Fails on missing key files or provider validation errors.

<a name="do-key-delete"></a>

## do:key:delete

- **What it does**: Deletes an SSH key from DigitalOcean.
- **When to use it**: Key rotation or stale key cleanup.
- **Prerequisites**: Existing account key and valid API token.
- **Effects on server/inventory/resources**: Removes provider-side key resource.
- **Related commands**: `do:key:list`, `do:key:add`.
- **Failure/guardrail behavior**: Requires explicit selection and reports provider deletion errors.

<a name="do-dns-list"></a>

## do:dns:list

- **What it does**: Lists DNS records for a DigitalOcean domain.
- **When to use it**: Reviewing DNS state before updates.
- **Prerequisites**: Valid API token and access to target domain.
- **Effects on server/inventory/resources**: Read-only operation.
- **Related commands**: `do:dns:set`, `do:dns:delete`, `site:dns:check`.
- **Failure/guardrail behavior**: Surfaces domain lookup and API errors directly.

<a name="do-dns-set"></a>

## do:dns:set

- **What it does**: Creates or updates a DNS record in DigitalOcean.
- **When to use it**: Pointing domains to servers or changing record targets.
- **Prerequisites**: API token with domain DNS edit access.
- **Effects on server/inventory/resources**: Upserts provider DNS records.
- **Related commands**: `do:dns:list`, `do:dns:delete`, `site:https`.
- **Failure/guardrail behavior**: Stops on record validation failures from the provider.

<a name="do-dns-delete"></a>

## do:dns:delete

- **What it does**: Deletes a DNS record from DigitalOcean.
- **When to use it**: Removing obsolete records.
- **Prerequisites**: API token and valid record target selection.
- **Effects on server/inventory/resources**: Removes provider DNS records.
- **Related commands**: `do:dns:list`, `do:dns:set`.
- **Failure/guardrail behavior**: Requires explicit record selection and returns deletion errors directly.

<a name="alias-compatibility"></a>

## Alias Compatibility

DigitalOcean commands also support alias names:

- `digitalocean:provision`
- `digitalocean:key:list`
- `digitalocean:key:add`
- `digitalocean:key:delete`
- `digitalocean:dns:list`
- `digitalocean:dns:set`
- `digitalocean:dns:delete`
