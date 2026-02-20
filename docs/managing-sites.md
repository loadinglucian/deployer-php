# Managing Sites

<!-- toc -->

- [Site Lifecycle](#site-lifecycle)
- [Shared Files](#shared-files)
- [DNS and HTTPS](#dns-and-https)
- [Access and Logs](#access-and-logs)
- [Rollbacks and Forward-Only Deployments](#rollbacks-and-forward-only-deployments)
- [Deleting a Site](#deleting-a-site)
- [Related References](#related-references)

<!-- /toc -->

Sites are the center of day-to-day operations in DeployerPHP. This guide covers the workflow around creating, deploying, securing, and retiring sites in a way that stays predictable under production pressure.

<a name="site-lifecycle"></a>

## Site Lifecycle

A practical lifecycle is:

1. Create the site with `site:create`.
2. Deploy code with `site:deploy`.
3. Verify DNS and enable TLS with `site:dns:check` and `site:https`.
4. Maintain shared assets and operational access over time.

This keeps infrastructure setup and release operations clearly separated.

<a name="shared-files"></a>

## Shared Files

Use the `site:shared:*` commands for persistent files that must survive deployments.

- `site:shared:list` helps you audit current shared state.
- `site:shared:push` uploads a local file into shared storage.
- `site:shared:pull` downloads a shared file for backup or investigation.

> [!INFO]
> The shared-file commands handle single files. If your app needs directory structures, create them in your deploy script.

Shared files are typically things like `.env` and user-managed artifacts. Keep this area small and explicit so each deploy remains predictable.

<a name="dns-and-https"></a>

## DNS and HTTPS

Use `site:dns:check` to verify resolver state before certificate issuance, then run `site:https`.

A healthy sequence is:

1. Point DNS records at the target server.
2. Confirm propagation with `site:dns:check`.
3. Enable HTTPS with `site:https`.

For sites configured without `www`, DNS checks intentionally skip `www` record validation.

If you use provider-integrated DNS commands, run DNS checks after updates so you validate resolver state, not only provider API state.

<a name="access-and-logs"></a>

## Access and Logs

Use `site:ssh` for interactive, site-local investigation. For broader telemetry, use `server:logs` and filter to site-level sources.

This split keeps shell-level debugging and log-level monitoring cleanly separated.

<a name="rollbacks-and-forward-only-deployments"></a>

## Rollbacks and Forward-Only Deployments

`site:rollback` documents DeployerPHP's forward-only deployment model.

Forward-only operations are recommended because they:

- Avoid hidden state drift after partial reversions.
- Keep change history auditable.
- Encourage quick, explicit fixes followed by clean redeploys.

If you must revert code behavior, revert in Git and deploy forward with a new release.

<a name="deleting-a-site"></a>

## Deleting a Site

Use `site:delete` to remove a site from the server and inventory.

> [!IMPORTANT]
> Site deletion is destructive. Validate backups and confirm scope before proceeding.

If remote cleanup fails, resolve that state explicitly instead of assuming the site was fully removed.

If you only want to remove local inventory while preserving remote files, confirm that intent carefully during the delete flow.

## Related References

- [Site Reference](reference-site.md)
- [Server Reference](reference-server.md)
- [Cron Reference](reference-cron.md)
- [Supervisor Reference](reference-supervisor.md)
