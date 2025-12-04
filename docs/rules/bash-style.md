# Bash Style

All rules MANDATORY. Based on https://style.ysap.sh/md

## Core Syntax

**Conditionals:** `[[ ... ]]` not `[ ... ]`

```bash
[[ -d /etc ]]              # CORRECT
[ -d /etc ]                # WRONG
```

**Command Substitution:** `$(...)` not backticks

```bash
foo=$(date)                # CORRECT
foo=`date`                 # WRONG
```

**Math:** `((...))` and `$((...))`, never `let`

```bash
if ((a > b)); then ...     # CORRECT
if [[ $a -gt $b ]]; then   # WRONG
```

**Functions:** No `function` keyword, always `local`

```bash
foo() { local i=5; }       # CORRECT
function foo { i=5; }      # WRONG
```

**Block Statements:** `then`/`do` same line

```bash
if true; then ...          # CORRECT
while true; do ...         # CORRECT
```

## Parameter Handling

**Expansion:** Prefer over external commands

```bash
prog=${0##*/}              # CORRECT - basename
nonumbers=${name//[0-9]/}  # CORRECT - remove numbers
prog=$(basename "$0")      # WRONG - external command
```

**Quoting:** Double for expansions, single for literals

```bash
echo "$foo"                # expansion needs quotes
bar='literal'              # no expansion
if [[ -n $foo ]]; then     # [[ ]] doesn't word-split
```

**Arrays:** Use bash arrays, not strings

```bash
modules=(a b c)            # CORRECT
for m in "${modules[@]}"   # CORRECT
modules='a b c'            # WRONG
```

## Error Handling

```bash
cd /path || exit           # CORRECT - exit on failure
cd /path                   # WRONG - unchecked
```

- Use `set -o pipefail` in playbooks
- Don't use `set -e` - explicit checking preferred
- Never use `eval`

## File Operations

```bash
# Streaming read
while IFS=: read -r user _; do
    echo "$user"
done < /etc/passwd

# CORRECT
grep foo file

# WRONG - useless cat
cat file | grep foo

# CORRECT - globs
for f in *; do ...

# WRONG - parsing ls
for f in $(ls); do ...
```

## Formatting

- Tabs for indentation
- Max 80 columns
- Semicolons only in control statements
- Max 1 blank line between sections
- Shebang: `#!/usr/bin/env bash`

## Quality Gates

```bash
composer bash        # Format playbooks/*.sh
composer bash:check  # Check only
```
