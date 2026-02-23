# Command Reference: Nginx

<!-- toc -->

- [At a Glance](#at-a-glance)
- [Details](#details)
- [Safety and Guardrails](#safety-and-guardrails)

<!-- /toc -->

Use `nginx:*` commands to control the web server runtime on managed hosts.

<a name="at-a-glance"></a>

## At a Glance

| Command         | Use it when you need to...                    |
| --------------- | --------------------------------------------- |
| `nginx:start`   | bring Nginx online                            |
| `nginx:stop`    | stop Nginx for controlled maintenance         |
| `nginx:restart` | reload Nginx state after changes or incidents |

<a name="details"></a>

## Details

These commands are service lifecycle controls. Prefer `nginx:restart` for most recovery and post-change workflows.

If you are diagnosing traffic failures, inspect logs before and after service actions.

<a name="safety-and-guardrails"></a>

## Safety and Guardrails

> [!IMPORTANT]
> Stopping Nginx makes web traffic unavailable for sites on the target server.
