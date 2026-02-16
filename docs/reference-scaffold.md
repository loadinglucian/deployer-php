# Command Reference: Scaffold

<!-- toc -->

- [scaffold:scripts](#scaffold-scripts)
- [scaffold:ai](#scaffold-ai)

<!-- /toc -->

Use scaffold commands to generate project-local templates that support deployment automation and AI-assisted operations.

<a name="scaffold-scripts"></a>

## scaffold:scripts

- **What it does**: Generates deployment, cron, and supervisor script templates under your project scaffolding path.
- **When to use it**: Initial project setup or when standard scripts need to be regenerated.
- **Prerequisites**: Run from the project working directory where scaffolding should be created.
- **Effects on server/inventory/resources**: Writes local script template files only.
- **Related commands**: `site:deploy`, `cron:create`, `supervisor:create`.
- **Failure/guardrail behavior**: Stops on local filesystem write conflicts or invalid path assumptions.

<a name="scaffold-ai"></a>

## scaffold:ai

- **What it does**: Generates AI skill files for supported agent directories with tiered guardrails.
- **When to use it**: Setting up AI-assisted diagnostics and operations workflows for a DeployerPHP project.
- **Prerequisites**: Run from project root with supported agent directory conventions.
- **Effects on server/inventory/resources**: Writes local AI skill configuration files only.
- **Related commands**: `server:info`, `server:logs`, `server:run`.
- **Failure/guardrail behavior**: Uses directory/tier validation and exits on unsafe or invalid scaffolding context.

For guided workflow details and safety posture, see [Automation & AI Guide](/docs/automation).
