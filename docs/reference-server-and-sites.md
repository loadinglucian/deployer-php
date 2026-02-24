# Servers & Sites Reference

<!-- toc -->

- [At a Glance](#at-a-glance)
    - [Server Commands](#server-commands)
    - [Site Commands](#site-commands)
- [Server Details](#server-details)
    - [Onboarding and Setup](#onboarding-and-setup)
    - [Diagnostics and Operations](#diagnostics-and-operations)
    - [Decommissioning](#decommissioning)
- [Site Details](#site-details)
    - [Provision, Deploy, Secure](#provision-deploy-secure)
    - [Shared File Operations](#shared-file-operations)
    - [DNS Behavior](#dns-behavior)
    - [Forward-Only Deployments](#forward-only-deployments)
    - [Removing a Site](#removing-a-site)

<!-- /toc -->

Use `server:*` commands to add servers, inspect runtime state, and perform remote operations. Use `site:*` commands to create, deploy, secure, inspect, and remove sites attached to your servers.

<a name="at-a-glance"></a>

## At a Glance

<a name="server-commands"></a>

### Server Commands

| Command           | Use it when you need to...                             |
| ----------------- | ------------------------------------------------------ |
| `server:add`      | register a new server in inventory                     |
| `server:install`  | install the baseline runtime stack                     |
| `server:info`     | inspect runtime state, resource pressure, and services |
| `server:firewall` | update UFW rules safely                                |
| `server:logs`     | stream server and service logs                         |
| `server:run`      | execute one remote command                             |
| `server:ssh`      | open an interactive remote session                     |
| `server:delete`   | remove a server from inventory or decommission it      |

<a name="site-commands"></a>

### Site Commands

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

<a name="server-details"></a>

## Server Details

<a name="onboarding-and-setup"></a>

### Onboarding and Setup

Use `server:add` first, then run the `server:install` command. This keeps inventory and host setup clearly separated.

`server:install` walks you through a full runtime provisioning flow. You'll configure:

- **Timezone** from a common presets list (UTC, major cities across regions). If your timezone isn't listed, select "Other..." to fetch the full IANA timezone list from the server
- **PHP version and extensions** with a curated set of pre-selected defaults (bcmath, curl, gd, intl, redis, and others). The extension list is filtered to what's actually available for your chosen PHP version, so you'll only see installable options
- **Deploy key** for Git access. You can use a server-generated key pair (the default) or provide your own. After installation completes, the deploy public key is printed so you can add it to your Git provider

`server:install` is additive. You can rerun it later to install additional PHP versions on the same server. When a PHP version is already installed, DeployerPHP detects it and prompts whether to set the new version as the default.

<a name="diagnostics-and-operations"></a>

### Diagnostics and Operations

Use `server:info` before making changes. The dashboard highlights resource pressure with color-coded indicators: load and memory values turn yellow at warning thresholds and red at critical levels. PHP-FPM sections show queue depth per installed version, highlighting any waiting requests or max-children events.

`server:firewall` keeps SSH access safe by always including your SSH port in the allowed list (it's excluded from the selection prompt entirely). Ports 80 and 443 are pre-selected when detected as actively listening, along with any ports already in existing UFW rules.

`server:logs` presents a multi-source selection menu built from your server's actual state:

- **Static sources** available on every provisioned server: system, supervisor, and cron logs
- **Port-detected services** shown only when running (Nginx, MariaDB, PostgreSQL, Redis, Memcached, SSH)
- **PHP-FPM logs** listed per installed PHP version
- **Per-site logs** including Nginx access logs, cron script logs, and supervisor program logs for each configured site
- **Group shortcuts** to tail all site logs, all cron logs, or all supervisor logs at once

You can filter the menu to a single site to focus on that site's logs exclusively.

Use `server:run` for scripted, one-shot checks. Use `server:ssh` when you need interactive investigation. Both `server:ssh` and `site:ssh` require the `pcntl` extension on your local PHP runtime.

<a name="decommissioning"></a>

### Decommissioning

`server:delete` requires you to type the server name to confirm deletion. For cloud-provisioned servers, DeployerPHP detects the cloud provider and offers to destroy the cloud resource alongside the inventory entry. If cloud destruction fails, a "remove from inventory anyway?" fallback lets you clean up local state. The command also lists and removes all associated sites from inventory.

<a name="site-details"></a>

## Site Details

<a name="provision-deploy-secure"></a>

### Provision, Deploy, Secure

A stable flow is `site:create`, then `site:deploy`, then `site:dns:check` and `site:https`.

`site:create` auto-detects subdomains (including two-part country-code TLDs like `.co.uk` and `.com.au`) and forces WWW mode to "none" for subdomain sites. Root domains keep the full WWW mode selection with "redirect to root" as the default.

`site:deploy` validates that your deploy script exists in the repository before proceeding. If `.deployer/scripts/deploy.sh` is missing, you'll see a warning with a suggestion to run `scaffold:scripts` to generate it. Missing scripts don't block deployment, but they are skipped.

<a name="shared-file-operations"></a>

### Shared File Operations

Use the `site:shared:*` commands for persistent single-file assets such as environment files and generated runtime artifacts.

<a name="dns-behavior"></a>

### DNS Behavior

`site:dns:check` validates resolver results and only checks `www` when the site is configured to use `www`.

<a name="forward-only-deployments"></a>

### Forward-Only Deployments

`site:rollback` is intentionally informational. Rather than reverting to a previous release, it explains the forward-only deployment philosophy:

- Rollbacks mask problems rather than fixing them
- Forward-only fixes create an auditable history of what changed and why
- Modern CI/CD makes deploying a fix as fast as rolling back

This is a deliberate design choice. When something breaks, the recommended path is to fix the issue, commit it, and redeploy.

<a name="removing-a-site"></a>

### Removing a Site

`site:delete` requires you to type the site domain to confirm deletion. If the server can't be reached or the remote cleanup fails, a "remove from inventory anyway?" fallback lets you clean up local state without leaving orphaned entries. You can also skip remote operations entirely to perform an inventory-only removal.
