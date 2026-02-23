# Command Reference: PHP-FPM

<!-- toc -->

- [At a Glance](#at-a-glance)
- [Details](#details)
- [Safety and Guardrails](#safety-and-guardrails)

<!-- /toc -->

Use `php:*` commands to control PHP-FPM runtime services for installed PHP versions.

<a name="at-a-glance"></a>

## At a Glance

| Command       | Use it when you need to...                       |
| ------------- | ------------------------------------------------ |
| `php:start`   | start PHP-FPM services                           |
| `php:stop`    | stop PHP-FPM services for maintenance            |
| `php:restart` | restart PHP-FPM after deploys or runtime changes |

<a name="details"></a>

## Details

`php:restart` is the common operational command after deployments to refresh runtime state.

Use `php:start` and `php:stop` for explicit lifecycle control during maintenance windows.

<a name="safety-and-guardrails"></a>

## Safety and Guardrails

> [!IMPORTANT]
> Stopping PHP-FPM interrupts PHP request handling for affected sites.
