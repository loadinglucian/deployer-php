# Command Reference: Cloudflare

<!-- toc -->

- [Configuration](#configuration)
- [At a Glance](#at-a-glance)
- [DNS Management](#dns-management)
- [Safety and Guardrails](#safety-and-guardrails)
- [Related](#related)

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

```shell
deployer cf:dns:list
deployer cf:dns:set
deployer cf:dns:delete
```

Use Cloudflare DNS updates together with `site:dns:check` when preparing HTTPS enablement.

<a name="safety-and-guardrails"></a>

## Safety and Guardrails

> [!INFO]
> Use API tokens scoped only to required zones and DNS permissions.

> [!IMPORTANT]
> DNS updates can route production traffic immediately. Double-check record names and targets before applying changes.

<a name="related"></a>

## Related

- [Operations](operations.md)
