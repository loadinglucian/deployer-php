# Scaffolding

<!-- toc -->

- [Deployment Scripts](#deployment-scripts)
- [AI Agent Skills](#ai-agent-skills)
- [Related References](#related-references)

<!-- /toc -->

Scaffolding commands generate project-local files that make deploy, cron, and process workflows repeatable.

<a name="deployment-scripts"></a>

## Deployment Scripts

Run `scaffold:scripts` in your project to generate baseline scripts in `.deployer/scripts/`.

These scripts are intended as starting points that you should adapt to your application's build, migration, and runtime needs.

<a name="ai-agent-skills"></a>

## AI Agent Skills

Run `scaffold:ai` to generate DeployerPHP-aware skill files for supported agent directories.

This flow helps you define what an assistant can do, from read-only observation to broader operational control.

> [!IMPORTANT]
> Start with the lowest permission tier that still solves your debugging task, then increase scope only when needed.

For broader operational context, see [Automation & AI Guide](automation.md).

## Related References

- [Scaffold Reference](reference-scaffold.md)
- [Server Reference](reference-server.md)
