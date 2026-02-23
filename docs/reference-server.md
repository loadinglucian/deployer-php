# Command Reference: Server

<!-- toc -->

- [At a Glance](#at-a-glance)
- [Details](#details)
- [Safety and Guardrails](#safety-and-guardrails)

<!-- /toc -->

Use the `server:*` commands to add servers, inspect runtime state, and perform remote operations.

## At a Glance

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

## Details

### Onboarding and setup

Use `server:add` first, then run the `server:install` command. This keeps inventory and host setup clearly separated.

`server:install` is additive, so you can rerun it later to extend runtime components.

### Diagnostics and operations

Use `server:info` before making changes, and pair it with `server:logs` during troubleshooting. The dashboard highlights load and memory pressure inline when the server is stressed.

Use `server:run` for scripted, one-shot checks. Use `server:ssh` when you need interactive investigation.

### Decommissioning

`server:delete` handles inventory cleanup and can also remove linked cloud resources when applicable.

## Safety and Guardrails

> [!INFO]
> `server:ssh` interactive mode requires the `pcntl` extension on your local PHP runtime.

> [!IMPORTANT]
> `server:delete` can be destructive for cloud-backed infrastructure. Confirm target server identity before you continue.
