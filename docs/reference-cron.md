# Command Reference: Cron

<!-- toc -->

- [At a Glance](#at-a-glance)
- [Details](#details)
- [Safety and Guardrails](#safety-and-guardrails)
- [Related Guides](#related-guides)

<!-- /toc -->

Use `cron:*` commands to manage scheduled jobs from inventory and apply them to servers.

## At a Glance

| Command       | Use it when you need to...                     |
| ------------- | ---------------------------------------------- |
| `cron:create` | add a scheduled job definition to a site       |
| `cron:sync`   | apply inventory cron definitions to the server |
| `cron:delete` | remove a scheduled job definition from a site  |

## Details

`cron:create` and `cron:delete` update local inventory state.

`cron:sync` is the command that updates remote crontab state.

This split lets you stage changes safely before applying them to production.

## Safety and Guardrails

> [!IMPORTANT]
> After creating or deleting cron definitions, run `cron:sync` to make remote state match inventory.

## Related Guides

- [Crons & Supervisors](crons-and-supervisors.md)
- [Scaffold Reference](reference-scaffold.md)
