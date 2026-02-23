# Command Reference: DigitalOcean

<!-- toc -->

- [Configuration](#configuration)
- [At a Glance](#at-a-glance)
- [SSH Key Management](#ssh-key-management)
- [Provisioning](#provisioning)
- [DNS Management](#dns-management)
- [Safety and Guardrails](#safety-and-guardrails)
- [Related](#related)

<!-- /toc -->

Use `do:*` commands to provision Droplets, manage SSH keys, and manage DNS records.

<a name="configuration"></a>

## Configuration

Set your token in the environment:

```dotenv
DIGITALOCEAN_TOKEN=...
```

Create the token at [cloud.digitalocean.com/account/api/tokens](https://cloud.digitalocean.com/account/api/tokens) with read/write access.

<a name="at-a-glance"></a>

## At a Glance

| Command         | Use it when you need to...                    |
| --------------- | --------------------------------------------- |
| `do:provision`  | create a Droplet and register it in inventory |
| `do:key:list`   | inspect existing account SSH keys             |
| `do:key:add`    | upload a local public key                     |
| `do:key:delete` | remove an account SSH key                     |
| `do:dns:list`   | list DNS records for a domain                 |
| `do:dns:set`    | create or update a DNS record                 |
| `do:dns:delete` | remove a DNS record                           |

Alias commands are also supported:

- `digitalocean:provision`
- `digitalocean:key:list`
- `digitalocean:key:add`
- `digitalocean:key:delete`
- `digitalocean:dns:list`
- `digitalocean:dns:set`
- `digitalocean:dns:delete`

<a name="ssh-key-management"></a>

## SSH Key Management

Use the `do:key:*` commands to keep account SSH keys aligned with your access policy before provisioning. You can list existing keys, upload a local public key, or remove a key you no longer need.

```shell
deployer do:key:list
deployer do:key:add
deployer do:key:delete
```

<a name="provisioning"></a>

## Provisioning

`do:provision` creates a Droplet and writes inventory entries so you can continue with `server:install` and site workflows immediately.

```shell
deployer do:provision
```

After provisioning, run the `server:install` command to prepare runtime services.

<a name="dns-management"></a>

## DNS Management

Use `do:dns:list` to inspect current records for a domain, then use `do:dns:set` and `do:dns:delete` for deliberate changes.

```shell
deployer do:dns:list
deployer do:dns:set
deployer do:dns:delete
```

<a name="safety-and-guardrails"></a>

## Safety and Guardrails

> [!INFO]
> Validate project and account context before provisioning or deleting resources.

> [!IMPORTANT]
> Provisioning and deletion can affect cost and data retention. Always confirm cleanup status after decommissioning.

When working with DigitalOcean resources, follow this order:

1. Validate credentials and scope.
2. Confirm account and project context.
3. Apply infrastructure and DNS changes.
4. Verify outcome with `site:dns:check` and service checks.
5. Confirm cleanup for any destructive operations.

<a name="related"></a>

## Related

- [Operations](operations.md)
