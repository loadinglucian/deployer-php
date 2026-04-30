# Documentation Rules

Rules for writing DeployerPHP user documentation.

> **IMPORTANT**
>
> - **Master index**: `docs/documentation.md` lists all doc files - update it when adding new files
> - **No CLI option docs**: Commands show a "non-interactive command replay" automatically

## Rules

- **Master index updates**: When adding a new documentation file (e.g., `docs/new-feature.md`), add it to `docs/documentation.md`. Do NOT update the master index for subsections within existing files.
- **No non-interactive command examples**: DeployerPHP commands display a "Non-interactive command replay" at the end of each execution. Don't duplicate this in docs—instead, mention that the replay is shown automatically.
- **No specific option/parameter references**: Don't document specific CLI options like `--keep-releases` or `--lines`. Commands show a "non-interactive command replay" with all options at the end of execution. This keeps docs maintainable—we don't need to update them when options change.
- **Explain what commands do, not what they output**: Describe the prompts, steps, and outcomes in prose rather than showing verbose terminal output or tables of parameter options.
- **Keep it scannable**: Use bullet lists for prompts, numbered lists for sequential steps.
- **TOC required**: Every documentation file must include a Table of Contents at the top, wrapped in `<!-- toc -->` and `<!-- /toc -->` HTML comment delimiters. List all H2 sections as links using kebab-case anchors.
- **Allowed callouts**: Use only `[!NOTE]` and `[!IMPORTANT]` callouts in docs.
- **Callout sequencing**: Do not place two callouts of the same type consecutively. Alternate types when two adjacent callouts are needed.

### Example: TOC Structure

```markdown
<!-- toc -->

- [Section One](#section-one)
- [Section Two](#section-two)
- [Section Three](#section-three)

<!-- /toc -->
```

### Example: Describe Prompts

```markdown
DeployerPHP will prompt you for:

- **Server name** - A friendly name for your server (e.g., "production", "web1")
- **Host** - The IP address or hostname of your server
- **Port** - SSH port (default: 22)
```

### Example: Describe Steps

```markdown
The installation process will:

1. Update package lists and install base packages
2. Configure Nginx with a monitoring endpoint
3. Set up the firewall (UFW)
4. Install your chosen PHP version with selected extensions
```

### Example: Describe Behavior Without Options

Instead of documenting specific options:

```markdown
<!-- DON'T -->

Use the `--lines` option to control output, or `--site` to filter logs.

<!-- DO -->

You can customize the output when running the command.
```

### Example: Anchor Pattern

```markdown
<a name="section-name"></a>

## Section Name
```

## Formatting

After modifying docs, run prettier:

```shell
bunx prettier --write "docs/**/*.md"
```
