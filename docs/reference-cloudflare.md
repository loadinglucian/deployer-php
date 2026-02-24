# Cloudflare Reference

<!-- toc -->

- [Configuration](#configuration)
- [At a Glance](#at-a-glance)
- [DNS Management](#dns-management)

<!-- /toc -->

Use `cf:*` commands to manage DNS records in Cloudflare zones.

<a name="configuration"></a>

## Configuration

Set your API token in the environment:

```dotenv
CLOUDFLARE_API_TOKEN=...
```

Create a token at [dash.cloudflare.com/profile/api-tokens](https://dash.cloudflare.com/profile/api-tokens) with `Zone:DNS:Edit` for the zones you manage.

<a name="at-a-glance"></a>

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

<a name="dns-management"></a>

## DNS Management

A practical sequence is list first, then apply create/update/delete changes once you confirm the target record set.

When creating or updating records, `cf:dns:set` prompts for Cloudflare proxy status (the "orange cloud"). Enabling the proxy hides your origin IP and activates CDN and DDoS protection. Proxy support is available for A, AAAA, and CNAME record types.

`cf:dns:delete` uses a two-tier confirmation: you type the record name first, then confirm with a yes/no prompt.

```shell
deployer cf:dns:list
deployer cf:dns:set
deployer cf:dns:delete
```

Use Cloudflare DNS updates together with `site:dns:check` when preparing HTTPS enablement.
