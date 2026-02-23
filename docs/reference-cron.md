# Command Reference: Cron

<!-- toc -->

- [At a Glance](#at-a-glance)
- [Details](#details)
- [Safety and Guardrails](#safety-and-guardrails)

<!-- /toc -->

Use `cron:*` commands to manage scheduled jobs from inventory and apply them to servers.

<a name="at-a-glance"></a>

## At a Glance

| Command       | Use it when you need to...                     |
| ------------- | ---------------------------------------------- |
| `cron:create` | add a scheduled job definition to a site       |
| `cron:sync`   | apply inventory cron definitions to the server |
| `cron:delete` | remove a scheduled job definition from a site  |

<a name="details"></a>

## Details

`cron:create` and `cron:delete` update local inventory state.

`cron:sync` is the command that updates remote crontab state.

This split lets you stage changes safely before applying them to production.

<a name="safety-and-guardrails"></a>

## Safety and Guardrails

> [!IMPORTANT]
> After creating or deleting cron definitions, run the `cron:sync` command to make remote state match inventory.
