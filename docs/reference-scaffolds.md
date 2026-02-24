# Scaffolds Reference

<!-- toc -->

- [At a Glance](#at-a-glance)
- [Details](#details)
    - [Deployment Scripts](#deployment-scripts)
    - [AI Agent Skills](#ai-agent-skills)

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

<a name="deployment-scripts"></a>

### Deployment Scripts

`scaffold:scripts` creates templates under your local project scaffolding path so you can version and customize them. These are the scripts that `site:deploy`, `cron:sync`, and `supervisor:sync` execute on the server.

<a name="ai-agent-skills"></a>

### AI Agent Skills

`scaffold:ai` helps you bootstrap operationally safe agent behavior. It supports multi-agent scaffolding, generating skill files for multiple agent directories in a single run.

DeployerPHP auto-detects existing agent directories (`.agents`, `.claude`) in your project. If any are found, it scaffolds all detected directories automatically. If none exist, you'll see a multiselect prompt to choose which agent directories to create.

You'll choose from three permission tiers:

- **Observer** provides read-only access (view logs, server info)
- **Debugger** adds inspect and safe shell capabilities (the default)
- **Admin** grants full access to manage infrastructure
