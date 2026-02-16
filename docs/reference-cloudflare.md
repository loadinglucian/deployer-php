# Command Reference: Cloudflare

<!-- toc -->

- [cf:dns:list](#cf-dns-list)
- [cf:dns:set](#cf-dns-set)
- [cf:dns:delete](#cf-dns-delete)
- [Alias Compatibility](#alias-compatibility)

<!-- /toc -->

Use these commands to manage DNS records in Cloudflare zones.

<a name="cf-dns-list"></a>

## cf:dns:list

- **What it does**: Lists DNS records in a selected Cloudflare zone.
- **When to use it**: Auditing current DNS state before updates.
- **Prerequisites**: Valid Cloudflare API token with zone DNS access.
- **Effects on server/inventory/resources**: Read-only operation.
- **Related commands**: `cf:dns:set`, `cf:dns:delete`, `site:dns:check`.
- **Failure/guardrail behavior**: Returns zone lookup and API auth/permission errors directly.

<a name="cf-dns-set"></a>

## cf:dns:set

- **What it does**: Creates or updates a DNS record in Cloudflare.
- **When to use it**: Creating new DNS targets or correcting existing records.
- **Prerequisites**: API token with DNS edit rights in the target zone.
- **Effects on server/inventory/resources**: Upserts provider DNS records.
- **Related commands**: `cf:dns:list`, `cf:dns:delete`, `site:https`.
- **Failure/guardrail behavior**: Aborts on provider-side validation errors.

<a name="cf-dns-delete"></a>

## cf:dns:delete

- **What it does**: Deletes a DNS record from Cloudflare.
- **When to use it**: DNS cleanup and decommissioning.
- **Prerequisites**: API token with delete permissions and resolvable record target.
- **Effects on server/inventory/resources**: Removes provider DNS records.
- **Related commands**: `cf:dns:list`, `cf:dns:set`.
- **Failure/guardrail behavior**: Requires explicit record targeting and surfaces deletion failures.

<a name="alias-compatibility"></a>

## Alias Compatibility

Cloudflare commands also support alias names:

- `cloudflare:dns:list`
- `cloudflare:dns:set`
- `cloudflare:dns:delete`
