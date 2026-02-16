# Command Reference: Site

<!-- toc -->

- [site:create](#site-create)
- [site:deploy](#site-deploy)
- [site:https](#site-https)
- [site:dns:check](#site-dns-check)
- [site:ssh](#site-ssh)
- [site:shared:list](#site-shared-list)
- [site:shared:push](#site-shared-push)
- [site:shared:pull](#site-shared-pull)
- [site:rollback](#site-rollback)
- [site:delete](#site-delete)

<!-- /toc -->

Use these commands to create, secure, deploy, inspect, and remove sites that are attached to servers in inventory.

<a name="site-create"></a>

## site:create

- **What it does**: Creates a site on a server and stores site metadata in inventory.
- **When to use it**: Before first deployment for a new domain or subdomain.
- **Prerequisites**: Installed server in inventory and domain-level DNS planning.
- **Effects on server/inventory/resources**: Creates remote site layout and web configuration, then writes site entry to inventory.
- **Related commands**: `site:deploy`, `site:https`, `site:delete`.
- **Failure/guardrail behavior**: Applies domain and WWW mode guardrails and exits on validation or remote setup failures.

<a name="site-deploy"></a>

## site:deploy

- **What it does**: Deploys application code using the release-based deployment workflow.
- **When to use it**: Initial launch and all subsequent application updates.
- **Prerequisites**: Existing site, reachable git repository, and remote deploy key access.
- **Effects on server/inventory/resources**: Updates repository state, creates a new release, updates current symlink, and reloads runtime where needed.
- **Related commands**: `site:create`, `site:shared:push`, `server:logs`.
- **Failure/guardrail behavior**: Keeps live release intact if a new release fails before activation.

<a name="site-https"></a>

## site:https

- **What it does**: Enables HTTPS using Certbot and configures automatic certificate renewal.
- **When to use it**: After DNS resolves to the target server and before production traffic ramps up.
- **Prerequisites**: Existing site and valid DNS resolution for the domain.
- **Effects on server/inventory/resources**: Installs and configures certificate material and TLS web server settings.
- **Related commands**: `site:dns:check`, `site:create`.
- **Failure/guardrail behavior**: Stops if DNS prerequisites are not satisfied or certificate provisioning fails.

<a name="site-dns-check"></a>

## site:dns:check

- **What it does**: Resolves site DNS records via Google Public DNS with retry behavior.
- **When to use it**: Before enabling HTTPS and when troubleshooting propagation.
- **Prerequisites**: Site must exist in inventory.
- **Effects on server/inventory/resources**: Read-only operation.
- **Related commands**: `site:https`, `aws:dns:set`, `cf:dns:set`, `do:dns:set`.
- **Failure/guardrail behavior**: Only checks `www` records when the site is configured to use `www`.

<a name="site-ssh"></a>

## site:ssh

- **What it does**: Opens an interactive SSH session directly in a site's remote directory.
- **When to use it**: Site-local inspection, release triage, and script-level troubleshooting.
- **Prerequisites**: Existing site bound to a reachable server and local interactive SSH support.
- **Effects on server/inventory/resources**: No direct changes unless you run mutating shell commands.
- **Related commands**: `server:ssh`, `site:deploy`.
- **Failure/guardrail behavior**: Exits on missing site/server associations or SSH session setup failures.

<a name="site-shared-list"></a>

## site:shared:list

- **What it does**: Lists files and directories in a site's shared path.
- **When to use it**: Verifying shared asset state before or after deployments.
- **Prerequisites**: Existing site and SSH connectivity.
- **Effects on server/inventory/resources**: Read-only operation.
- **Related commands**: `site:shared:push`, `site:shared:pull`.
- **Failure/guardrail behavior**: Returns clear errors when the shared path cannot be accessed.

<a name="site-shared-push"></a>

## site:shared:push

- **What it does**: Uploads a local file to the site's shared directory.
- **When to use it**: Syncing environment files or other persistent assets needed by deployments.
- **Prerequisites**: Existing local source file and reachable site.
- **Effects on server/inventory/resources**: Writes or overwrites a file in remote shared storage.
- **Related commands**: `site:shared:list`, `site:shared:pull`, `site:deploy`.
- **Failure/guardrail behavior**: Fails on invalid paths, transfer errors, or permissions issues.

<a name="site-shared-pull"></a>

## site:shared:pull

- **What it does**: Downloads a shared file from the server to your local machine.
- **When to use it**: Backups, audit exports, or local troubleshooting of production-like files.
- **Prerequisites**: Existing remote file and writeable local destination.
- **Effects on server/inventory/resources**: Reads remote file and writes locally.
- **Related commands**: `site:shared:list`, `site:shared:push`.
- **Failure/guardrail behavior**: Stops on missing remote files or local write failures.

<a name="site-rollback"></a>

## site:rollback

- **What it does**: Explains DeployerPHP's forward-only deployment model.
- **When to use it**: When considering rollback strategies and operational policy.
- **Prerequisites**: None.
- **Effects on server/inventory/resources**: No changes.
- **Related commands**: `site:deploy`.
- **Failure/guardrail behavior**: N/A (informational command).

<a name="site-delete"></a>

## site:delete

- **What it does**: Removes a site from the server and inventory.
- **When to use it**: Decommissioning environments or removing misconfigured sites.
- **Prerequisites**: Site exists in inventory.
- **Effects on server/inventory/resources**: Removes remote site resources and deletes the inventory record.
- **Related commands**: `site:create`, `site:deploy`, `site:https`.
- **Failure/guardrail behavior**: Requires explicit confirmation and reports partial cleanup failures.
