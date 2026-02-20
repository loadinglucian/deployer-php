# Command Reference: Cloudflare

<!-- toc -->

- [At a Glance](#at-a-glance)
- [Details](#details)
- [Safety and Guardrails](#safety-and-guardrails)
- [Related Guides](#related-guides)

<!-- /toc -->

Use `cf:*` commands to manage DNS records in Cloudflare zones.

## At a Glance

| Command         | Use it when you need to...    |
| --------------- | ----------------------------- |
| `cf:dns:list`   | inspect DNS records in a zone |
| `cf:dns:set`    | create or update a DNS record |
| `cf:dns:delete` | delete a DNS record           |

Alias commands are also supported:

- `cloudflare:dns:list`
- `cloudflare:dns:set`
- `cloudflare:dns:delete`

## Details

A practical sequence is list first, then apply create/update/delete changes once you confirm the target record set.

Use Cloudflare DNS updates together with `site:dns:check` when preparing HTTPS enablement.

## Safety and Guardrails

> [!INFO]
> Use API tokens scoped only to required zones and DNS permissions.

> [!IMPORTANT]
> DNS updates can route production traffic immediately. Double-check record names and targets before applying changes.

## Related Guides

- [Cloud Providers](cloud-providers.md)
- [Managing Sites](managing-sites.md)
