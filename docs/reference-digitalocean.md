# Command Reference: DigitalOcean

<!-- toc -->

- [At a Glance](#at-a-glance)
- [Details](#details)
- [Safety and Guardrails](#safety-and-guardrails)
- [Related Guides](#related-guides)

<!-- /toc -->

Use `do:*` commands to provision Droplets, manage SSH keys, and manage DNS records.

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

## Details

`do:provision` is the main infrastructure entrypoint and is usually followed by `server:install`.

Use key and DNS commands to keep account access and routing state aligned with your deployment topology.

## Safety and Guardrails

> [!INFO]
> Validate project/account context before provisioning or deleting resources.

> [!IMPORTANT]
> Provisioning and deletion affect cost and data retention. Confirm cleanup status after decommissioning.

## Related Guides

- [Cloud Providers](cloud-providers.md)
- [Managing Servers](managing-servers.md)
- [Managing Sites](managing-sites.md)
