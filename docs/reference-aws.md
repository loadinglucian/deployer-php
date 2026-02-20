# Command Reference: AWS

<!-- toc -->

- [At a Glance](#at-a-glance)
- [Details](#details)
- [Safety and Guardrails](#safety-and-guardrails)
- [Related Guides](#related-guides)

<!-- /toc -->

Use `aws:*` commands to provision EC2 servers, manage SSH keys, and manage Route53 DNS records.

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

## Details

### Provisioning and inventory

`aws:provision` creates cloud resources and writes inventory entries so you can continue with `server:install` and site workflows immediately.

### SSH key management

Use the `aws:key:*` commands to keep key inventory aligned with your access policy before provisioning.

### DNS record management

Use `aws:dns:list` to inspect current records, then use `aws:dns:set` and `aws:dns:delete` for deliberate changes.

## Safety and Guardrails

> [!INFO]
> Confirm account, region, and hosted zone context before mutating DNS or provisioning resources.

> [!IMPORTANT]
> `aws:provision` and deletion workflows can affect cost and data retention. Validate cleanup status after destructive actions.

## Related Guides

- [Cloud Providers](cloud-providers.md)
- [Managing Servers](managing-servers.md)
- [Managing Sites](managing-sites.md)
