# Command Reference: Supervisor

<!-- toc -->

- [At a Glance](#at-a-glance)
- [Details](#details)
- [Safety and Guardrails](#safety-and-guardrails)

<!-- /toc -->

Use `supervisor:*` commands to define and operate long-running site processes.

<a name="at-a-glance"></a>

## At a Glance

| Command              | Use it when you need to...                     |
| -------------------- | ---------------------------------------------- |
| `supervisor:create`  | add a process definition for a site            |
| `supervisor:sync`    | write inventory definitions to server config   |
| `supervisor:start`   | start the supervisord service                  |
| `supervisor:stop`    | stop the supervisord service                   |
| `supervisor:restart` | restart supervisord after changes or incidents |
| `supervisor:delete`  | remove a process definition from a site        |

<a name="details"></a>

## Details

Use `supervisor:create` and `supervisor:delete` to manage inventory definitions.

Run the `supervisor:sync` command after definition changes to apply them remotely.

Use `supervisor:start`, `supervisor:stop`, and `supervisor:restart` for daemon lifecycle control.

<a name="safety-and-guardrails"></a>

## Safety and Guardrails

> [!NOTE]
> Definition changes are not live until `supervisor:sync` runs.

> [!IMPORTANT]
> Restarting or stopping supervisord affects all managed programs on the target server.
