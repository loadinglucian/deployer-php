# Command Reference: Supervisor

<!-- toc -->

- [At a Glance](#at-a-glance)
- [Details](#details)
- [Safety and Guardrails](#safety-and-guardrails)
- [Related Guides](#related-guides)

<!-- /toc -->

Use `supervisor:*` commands to define and operate long-running site processes.

## At a Glance

| Command              | Use it when you need to...                     |
| -------------------- | ---------------------------------------------- |
| `supervisor:create`  | add a process definition for a site            |
| `supervisor:sync`    | write inventory definitions to server config   |
| `supervisor:start`   | start the supervisord service                  |
| `supervisor:stop`    | stop the supervisord service                   |
| `supervisor:restart` | restart supervisord after changes or incidents |
| `supervisor:delete`  | remove a process definition from a site        |

## Details

Use `supervisor:create` and `supervisor:delete` to manage inventory definitions.

Run the `supervisor:sync` command after definition changes to apply them remotely.

Use `supervisor:start`, `supervisor:stop`, and `supervisor:restart` for daemon lifecycle control.

## Safety and Guardrails

> [!INFO]
> Definition changes are not live until `supervisor:sync` runs.

> [!IMPORTANT]
> Restarting or stopping supervisord affects all managed programs on the target server.

## Related Guides

- [Crons & Supervisors](crons-and-supervisors.md)
- [Managing Services](managing-services.md)
