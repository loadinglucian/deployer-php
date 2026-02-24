# Command Reference: Scaffold

<!-- toc -->

- [At a Glance](#at-a-glance)
- [Details](#details)
- [Safety and Guardrails](#safety-and-guardrails)

<!-- /toc -->

Use `scaffold:*` commands to generate project-local scripts and AI skill files.

<a name="at-a-glance"></a>

## At a Glance

| Command            | Use it when you need to...                             |
| ------------------ | ------------------------------------------------------ |
| `scaffold:scripts` | generate baseline deploy, cron, and supervisor scripts |
| `scaffold:ai`      | generate agent skills with tiered permissions          |

<a name="details"></a>

## Details

`scaffold:scripts` creates templates under your local project scaffolding path so you can version and customize them.

`scaffold:ai` helps you bootstrap operationally safe agent behavior based on selected permission tiers.

<a name="safety-and-guardrails"></a>

## Safety and Guardrails

> [!NOTE]
> Treat scaffold output as a starting point. Review and adapt generated files before using them in production.

> [!IMPORTANT]
> For `scaffold:ai`, choose the lowest permission tier that still enables your troubleshooting workflow.
