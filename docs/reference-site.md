# Command Reference: Site

<!-- toc -->

- [At a Glance](#at-a-glance)
- [Details](#details)
- [Safety and Guardrails](#safety-and-guardrails)

<!-- /toc -->

Use the `site:*` commands to create, deploy, secure, inspect, and remove sites attached to your servers.

<a name="at-a-glance"></a>

## At a Glance

| Command            | Use it when you need to...                            |
| ------------------ | ----------------------------------------------------- |
| `site:create`      | create site structure and register inventory metadata |
| `site:deploy`      | push a new release with the standard deploy workflow  |
| `site:https`       | enable TLS certificates and renewal                   |
| `site:dns:check`   | verify DNS propagation before TLS or cutover          |
| `site:ssh`         | open an interactive shell in the site context         |
| `site:shared:list` | inspect files in shared storage                       |
| `site:shared:push` | upload a local file to shared storage                 |
| `site:shared:pull` | download a shared file from the server                |
| `site:rollback`    | review forward-only deployment guidance               |
| `site:delete`      | remove a site from server and inventory               |

<a name="details"></a>

## Details

### Provision, deploy, secure

A stable flow is `site:create`, then `site:deploy`, then `site:dns:check` and `site:https`.

### Shared file operations

Use the `site:shared:*` commands for persistent single-file assets such as environment files and generated runtime artifacts.

### DNS behavior

`site:dns:check` validates resolver results and only checks `www` when the site is configured to use `www`.

<a name="safety-and-guardrails"></a>

## Safety and Guardrails

> [!NOTE]
> `site:rollback` is intentionally informational. The recommended operational model is forward-only fixes and redeploys.

> [!IMPORTANT]
> Run `site:dns:check` before `site:https` so certificate issuance is attempted only after DNS is ready. Also note that `site:delete` is destructive, so confirm backups and target scope before you proceed.
