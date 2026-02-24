# DigitalOcean Reference

<!-- toc -->

- [Configuration](#configuration)
- [At a Glance](#at-a-glance)
- [SSH Key Management](#ssh-key-management)
- [Provisioning](#provisioning)
- [DNS Management](#dns-management)

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

`do:provision` creates a Droplet and writes inventory entries so you can continue with `server:install` and site workflows immediately. You can also configure optional settings during provisioning, including VPC selection, monitoring (enabled by default, free), IPv6 (enabled by default, free), and automatic backups.

If provisioning fails after the Droplet is created, DeployerPHP automatically rolls back the Droplet so you don't accumulate orphaned resources.

```shell
deployer do:provision
```

After provisioning, run the `server:install` command to prepare runtime services.

<a name="dns-management"></a>

## DNS Management

Use `do:dns:list` to inspect current records for a domain, then use `do:dns:set` and `do:dns:delete` for deliberate changes.

`do:dns:delete` uses a two-tier confirmation: you type the record name first, then confirm with a yes/no prompt.

```shell
deployer do:dns:list
deployer do:dns:set
deployer do:dns:delete
```
